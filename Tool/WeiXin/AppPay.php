<?php
/**
 * AppPay 微信jsapi支付
 * @author: edge
 * @time: 2017/10/15
 */
namespace Someline\Tool\WeiXin;
use Someline\Exceptions\MobileApiException;
use Someline\Models\Common\WeixinPayLog;
use Someline\Services\OrderService;

require_once "WxpayAPI_php_v3.0.1/lib/WxPay.Api.php";
require_once "WxpayAPI_php_v3.0.1/lib/WxPay.Notify.php";

class AppPay extends \WxPayNotify
{

	/**
     * [$instance description]
     * @var null
     */
    protected static $instance = null;

    /**
     * [$error description]
     * @var null
     */
	public $error = null;

    /**
     * [$is_test description]
     * @var boolean
     */
    public $is_test = false;

    /**
     * [__construct description]
     */
    public function __construct()
    {
        if (env('APP_ENV') != 'pro') {
            $this->is_test = true;
        }
        $wx_payment_info['wx_appid'] = env('APP_WX_APPID','');
        $wx_payment_info['wx_mchid'] = env('APP_WX_MCHID','');
        $wx_payment_info['wx_appsecret'] = env('APP_WX_APPSECRET','');
        $wx_payment_info['wx_pay_sign_key'] = env('APP_WX_PAY_SIGN_KEY','');
        $wx_payment_info['wx_notify_url'] = env('APP_WX_NOTIFY_URL','');
        if (empty($wx_payment_info['wx_appid']) || empty($wx_payment_info['wx_mchid']) || empty($wx_payment_info['wx_pay_sign_key']) || empty( $wx_payment_info['wx_notify_url'])) {
            throw new \Exception('微信支付配置不正确'); 
        }
        sessions('wx_payment_info',$wx_payment_info);
    }

    /**
     * 单例
     * @return AppPay
     */
    public static function getInstance()
    {
        if(empty(static::$instance)){
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * @param array $orderData
     * @return array
     * @throws MobileApiException
     */
    public function WxAppPay($orderData = [])
    {
        $come_from = 0;
        $cs = request()->header('cs');
        if ($cs == 'a') {
            $come_from = 3;
        } elseif ($cs == 'i') {
            $come_from = 4;
        }
        //调用微信支付统一下单接口
        $input = new \WxPayUnifiedOrder();
        $input->SetBody($orderData['body']);
        $input->SetOut_trade_no($orderData['system_num']);
        $input->SetTotal_fee($orderData['total_fee']);
        $input->SetTrade_type("APP");
        $order = \WxPayApi::unifiedOrder($input);

        //记录下单调用日志
        $this->RecordLog($orderData,$input->GetValues(),$order,$come_from);

        if(isset($order['result_code']) && $order['result_code'] == 'SUCCESS'){
            $time = time();
            $wx_payment_info = sessions('wx_payment_info');
            // app支付独特加密，提供给app接口用
            $sign['appid']     = $wx_payment_info['wx_appid'];
            $sign['partnerid'] = $wx_payment_info['wx_mchid'];
            $sign['prepayid']  = $order['prepay_id'];
            $sign['noncestr']  = $order['nonce_str'];
            $sign['timestamp'] = $time;
            $sign['package']   = 'Sign=WXPay';
            // 返回数据
            $data['partner_id'] = $order['mch_id'];
            $data['prepay_id'] = $order['prepay_id'];
            $data['nonce_str'] = $order['nonce_str'];
            $data['timestamp'] = (string) $time;
            $data['package_value'] = 'Sign=WXPay';
            $data['sign'] = $this->MakeAppSign($sign);
            return $data;
        } else {
            throw new MobileApiException('微信支付失败');
        }
    }

    /** 记录日志
     * @param $orderData
     * @param $data
     * @param $result
     * @param $come_from
     */
    private function RecordLog($orderData, $data, $result, $come_from)
    {
        //日志记录
        $weixin_pay_log_model = new WeixinPayLog();
        $weixin_pay_log_data['user_id'] = $orderData['uid'];
        $weixin_pay_log_data['type'] = 1;
        $weixin_pay_log_data['system_num'] = $orderData['system_num'];
        $weixin_pay_log_data['source_id'] = $orderData['source_id'];
        $weixin_pay_log_data['source_table'] = $orderData['source_table'];
        $weixin_pay_log_data['data'] = json_encode($data);
        $weixin_pay_log_data['result'] = json_encode($result);
        $weixin_pay_log_data['come_from'] = $come_from;
        $weixin_pay_log_model->saveData($weixin_pay_log_data);
    }

    /** [ToUrlParams 格式化参数格式化成url参数]
     * @param $sign
     * @return string
     */
    public function AppToUrlParams($sign)
    {
        $buff = "";
        foreach ($sign as $k => $v)
        {
            if($k != "sign" && $v != "" && !is_array($v)){
                $buff .= $k . "=" . $v . "&";
            }
        }
        $buff = trim($buff, "&");
        return $buff;
    }

    /** 返回APP调起支付使用的签名
     * @param $sign
     * @return string
     */
    public function MakeAppSign($sign)
    {
        $wx_payment_info = sessions('wx_payment_info');
        //签名步骤一：按字典序排序参数
        ksort($sign);
        $string = $this->AppToUrlParams($sign);
        //签名步骤二：在string后加入KEY
        $string = $string . "&key=" . $wx_payment_info['wx_pay_sign_key'];
        //签名步骤三：MD5加密
        $string = md5($string);
        //签名步骤四：所有字符转为大写
        $result = strtoupper($string);
        return $result;
    }

    /**
     * [IosPay 苹果iap购买产品]
     * @author Edge
     * @time 2017/10/23
     * @param integer $product_id_key [description]
     * @param integer $type           [description]
     * @param integer $uid            [description]
     */
//    public function IosPay($product_id_key = 0, $type = 1, $uid = 0)
//    {
//        $res = array('status_code' => 400);
//        if (!empty($product_id_key) && !empty($type) && !empty($uid)) {
//            $come_from = 0;
//            $cs = request()->header('cs');
//            if ($cs == 'a') {
//                $come_from = 3;
//            } elseif ($cs == 'i') {
//                $come_from = 4;
//            }
//            if ($come_from == 4) {
//                // 判断用户余额有多少
//                $user_balance_model = new UserBalance();
//                $user_balance_map['user_id'] = $uid;
//                $user_balance = $user_balance_model->getFirst($user_balance_map);
//                if (!empty($user_balance) && $user_balance['ios_balance'] > 0) {
//                    // 生成订单
//                    $pay_type = 4;
//                    $order_info = KnowledgeOrdersService::getInstance()->makeOrder($product_id_key,$type,$uid,$come_from,$pay_type,$user_balance['ios_balance']);
//                    if (!empty($order_info)) {
//                        $knowledge_orders_model = new KnowledgeOrders();
//                        $source_id = $order_info['source_id'];
//                        $source_table = $order_info['source_table'];
//                        $price = $order_info['price'];
//                        $order_id = $order_info['id'];
//                        $system_num = $order_info['system_num'];
//                        DB::beginTransaction();
//                        // 扣除余额
//                        $min_balance = $user_balance['balance'] - $order_info['balance_price'];
//                        $min_balance = $min_balance > 0 ? $min_balance : 0;
//                        $min_ios_balance = $user_balance['ios_balance'] - $order_info['balance_price'];
//                        $min_ios_balance = $min_ios_balance > 0 ? $min_ios_balance : 0;
//                        $user_balance_update['balance'] = $min_balance;
//                        $user_balance_update['ios_balance'] = $min_ios_balance;
//                        $user_balance_res = $user_balance_model->saveData($user_balance_update,$uid,'user_id');
//                        // 添加log
//                        $user_balance_log_model = new UserBalanceLog();
//                        $user_balance_log['user_id'] = $uid;
//                        $user_balance_log['balance'] = -$min_balance;
//                        $user_balance_log['ios_balance'] = -$min_ios_balance;
//                        $user_balance_log['come_from'] = $come_from;
//                        $user_balance_res = $user_balance_log_model->saveData($user_balance_log);
//                        if ($user_balance_res && $user_balance_res) {
//                            // 更新数据
//                            $update['status'] = 2;
//                            $update['pay_time'] = time();
//                            $update['pay_num'] = $system_num;
//                            // 看有没有收藏
//                            $collection_model = new Collection();
//                            $collect_map['uid'] = $uid;
//                            $collect_map['is_collection'] = 1;
//                            $collect_map['source_id'] = $source_id;
//                            $collect_map['source_table'] = $source_table;
//                            $has_collect = $collection_model->getFirst($collect_map);
//                            if ($has_collect) {
//                                $knowledge_orders_collect_model = new KnowledgeOrdersCollect();
//                                $collect['uid'] = $uid;
//                                $collect['collect_id'] = $has_collect['id'];
//                                $collect['order_id'] = $order_id;
//                                $collect['source_id'] = $source_id;
//                                $collect['source_table'] = $source_table;
//                                $knowledge_orders_collect_model->saveData($collect);
//                            }
//                            // 更新订单状态
//                            DB::enableQueryLog();
//                            $knowledge_orders_res = $knowledge_orders_model->saveData($update,$system_num,'system_num');
//                            $res_sql_log = DB::getQueryLog();
//                            $knowledge_orders_logs_model = new KnowledgeOrdersLogs();
//                            $knowledge_orders_logs_data['order_id'] = $order_id;
//                            $knowledge_orders_logs_data['type'] = 'update';
//                            $knowledge_orders_logs_data['update_sql'] = json_encode($res_sql_log);
//                            $knowledge_orders_logs_model->saveData($knowledge_orders_logs_data);
//                            if ($knowledge_orders_res) {
//                                // 添加Transactions
//                                $transactions_model = new Transactions();
//                                $transactions_data['user_id'] = $uid;
//                                $transactions_data['price'] = $price;
//                                $transactions_type = 5; // 知识产品
//                                $transactions_data['type'] = $transactions_type;
//                                $transactions_data['order_sn'] = $system_num;
//                                $transactions_data['dotran'] = 2; // 支出
//                                $transactions_data['create_time'] = time();
//                                $transactions_model->saveData($transactions_data);
//                                DB::commit();
//                                $res['status_code'] = 200;
//                            } else {
//                                DB::rollBack();
//                            }
//                        } else {
//                            DB::rollBack();
//                            $res['message'] = '使用余额失败';
//                        }
//                    } else {
//                        $res['message'] = KnowledgeOrdersService::getInstance()->error;
//                    }
//                } else {
//                    $res['message'] = '请先充值余额';
//                }
//            } else {
//                $res['message'] = '目前只支持平台充值余额';
//            }
//        } else {
//            $res['message'] = '参数出错';
//        }
//        return $res;
//    }
    public function NotifyProcess($data, &$msg)
    {
        // 商户订单号
        $system_num = $data['out_trade_no'];
        // 微信订单号
        $transaction_id = isset($data['transaction_id']) ? $data['transaction_id'] : 0;
        $res = false;
        if ($transaction_id) {
            //查询微信订单号是否存在
            $res = $this->Queryorder($transaction_id);
        }
        if ($res) {
            //内部订单处理
            $orderService = new OrderService();
            $res = $orderService->handleOrder($system_num, $transaction_id, $data);
        }
        return $res;
    }

    /**
     * [Queryorder 查询订单]
     * @author: edge
     * @time: 2017/10/20
     * @param [type] $transaction_id [description]
     */
    public function Queryorder($transaction_id)
    {
        error_log(date("Y-m-d H:i:s Queryorder:\r\n"),3, "/tmp/weixin_notify.log");
        $input = new \WxPayOrderQuery();
        $input->SetTransaction_id($transaction_id);
        $result = \WxPayApi::orderQuery($input);
        error_log(date("Y-m-d H:i:s Queryorder:\r\n").json_encode($result),3, "/tmp/weixin_notify.log");
        if(array_key_exists("return_code", $result)
            && array_key_exists("result_code", $result)
            && $result["return_code"] == "SUCCESS"
            && $result["result_code"] == "SUCCESS")
        {
            return true;
        }
        return false;
    }


}
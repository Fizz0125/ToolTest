<?php
/**
 * AppletPay 微信小程序支付
 * @author: edge
 * @time: 2017/11/08
 */
namespace Someline\Tool\WeiXin;
use Someline\Models\Common\WeixinPayLog;

require_once "WxpayAPI_php_v3.0.1/example/WxPay.JsApiPay.php";
require_once "WxpayAPI_php_v3.0.1/lib/WxPay.Notify.php";

class AppletPay extends \WxPayNotify
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
	 * [$pay_keys 支付需要的参数,其中total_fee单位为分]
	 * @var [type]
	 */
	public $pay_keys = ['openid','body','system_num','total_fee','uid','source_id','source_table'];

	/**
     * [__construct description]
     */
    public function __construct()
    {
        $wx_payment_info['wx_appid'] = env('APPLET_WX_APPID','');
        $wx_payment_info['wx_mchid'] = env('APPLET_WX_MCHID','');
        $wx_payment_info['wx_appsecret'] = env('APPLET_WX_APPSECRET','');
        $wx_payment_info['wx_pay_sign_key'] = env('APPLET_WX_PAY_SIGN_KEY','');
        $wx_payment_info['wx_notify_url'] = env('APPLET_WX_NOTIFY_URL','');
        if (empty($wx_payment_info['wx_appid']) || empty($wx_payment_info['wx_mchid']) || empty($wx_payment_info['wx_pay_sign_key']) || empty( $wx_payment_info['wx_notify_url'])) {
            throw new \Exception('微信支付配置不正确'); 
        }
        sessions('wx_payment_info',$wx_payment_info);
    }

    /**
     * 单例
     * @return class
     */
    public static function getInstance()
    {
        if(empty(static::$instance)){
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * [pay 小程序预支付数据]
     * @param  array  $orderData [description]
     * @return [type]            [description]
     */
    public function pay($orderData = [])
    {
        $can_pay = false;
    	$data = [];
    	if (!empty($orderData)) {
    		$pay_keys_bool = true;
    		foreach ($this->pay_keys as $key => $value) {
    			if (!isset($orderData[$value])) {
    				$this->error = $value . '参数必传';
    				$pay_keys_bool = false;
    				break;
    			}
    		}
    		if ($pay_keys_bool) {
    			$come_from = 6; // 小程序过来的
    			$input = new \WxPayUnifiedOrder();
    			$input->SetBody($orderData['body']);
                $input->SetOut_trade_no($orderData['system_num']);
                $input->SetTotal_fee($orderData['total_fee']);
                $input->SetTrade_type("JSAPI");
                $input->SetOpenid($orderData['openid']);
                $order = \WxPayApi::unifiedOrder($input);

                if(isset($order['result_code']) && $order['result_code'] == 'SUCCESS'){
                    // 预支付交易会话标识 发消息要用到
                    $prepay_id = $order['prepay_id'];
                	$JsApiPay = new \JsApiPay();
                    $order = $JsApiPay->GetJsApiParameters($order);
                    $order = json_decode($order,1);
                    $order['prepay_id'] = $prepay_id;
                    $data = $order;
                    $can_pay = true;

                    ApplentApi::getInstance()->setFormId($orderData['openid'], $prepay_id);
                } else {
                    $this->error = $order['return_msg'];
                }
                $weixin_pay_log_model = new WeixinPayLog();
                $weixin_pay_log_data['user_id'] = $orderData['uid'];
                $weixin_pay_log_data['type'] = 1;
                $weixin_pay_log_data['system_num'] = $orderData['system_num'];
                $weixin_pay_log_data['source_id'] = $orderData['source_id'];
                $weixin_pay_log_data['source_table'] = $orderData['source_table'];
                $weixin_pay_log_data['data'] = json_encode($input->GetValues());
                $weixin_pay_log_data['result'] = json_encode($order);
                $weixin_pay_log_data['come_from'] = $come_from;
                $weixin_pay_log_model->saveData($weixin_pay_log_data);
    		}
    	} else {
    		$this->error = '订单数据不能为空';
    	}
    	if (!$can_pay) {
    		throw new \Exception($this->error); 
    	} else {
    		return $data;
    	}
    }

    /**
     * [query 查询订单]
     * @param  string $transaction_id [description]
     * @param  string $out_trade_no   [description]
     * @return [type]                 [description]
     */
    public function query($transaction_id = '',$out_trade_no = '')
    {
    	if (empty($transaction_id) && empty($out_trade_no)) {
    		throw new \Exception('订单查询接口中，out_trade_no、transaction_id至少填一个！'); 
    	}
    	$input = new \WxPayOrderQuery();
    	if ($transaction_id) {
			$input->SetTransaction_id($transaction_id);
    	}
    	if ($out_trade_no) {
			$input->SetOut_trade_no($out_trade_no);
    	}
		$result = \WxPayApi::orderQuery($input);
		return $result;
    }

    public function refund($transaction_id = '',$out_trade_no = '')
    {
    	
    }

    /**
	 * [NotifyProcess 重写回调处理函数]
	 * @author: edge
 	 * @time: 2017/10/20
	 * @param [type] $data [description]
	 * @param [type] &$msg [description]
	 */
	public function NotifyProcess($data, &$msg)
	{
		error_log(date("Y-m-d H:i:s NotifyProcess:\r\n"),3, "/tmp/weixin_notify.log");
		$notfiyOutput = array();
		if(!array_key_exists("transaction_id", $data)){
			$msg = "输入参数不正确";
			return false;
		}
		//查询订单，判断订单真实性
		if(!$this->Queryorder($data["transaction_id"])){
			$msg = "订单查询失败";
			return false;
		}
		// 在这里进行
		return true;
	}

	/**
	 * [Queryorder 查询订单]
	 * @author: edge
 	 * @time: 2017/10/20
	 * @param [type] $transaction_id [description]
	 */
	public function Queryorder($transaction_id,$notify = '')
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

	/**
	 * [resolveXml 解析xml]
	 * @author: edge
     * @time: 2017/10/20
	 * @param  string $xml [description]
	 * @return [type]      [description]
	 */
	public function resolveXml($xml='')
	{
		if($xml){
			return \WxPayResults::Init($xml);
		}else{
			return false;
		}
	}

}
<?php
/**
 * Payment 微信商家付款
 * @author: Fizz
 * @time: 2018.03.15
 */
namespace Someline\Tool\WeiXin;

use Someline\Services\KnowledgeOrdersService;
use Someline\Models\Common\PublicFollow;
use Someline\Models\Common\WeixinPayLog;

require_once "Payment/WxPayHelper.php";

class Payment extends \WxPayNotify
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


    public $payConfig;

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
        $wx_payment_info['mch_appid'] = env('PAYMENT_WX_APPID','');
        $wx_payment_info['mchid']     = env('PAYMENT_WX_MCHID','');
        $wx_payment_info['appsecret'] = env('PAYMENT_WX_APPSECRET','');
        $wx_payment_info['pay_sign_key'] = env('PAYMENT_WX_PAY_SIGN_KEY','');
        $wx_payment_info['notify_url'] = env('PAYMENT_WX_NOTIFY_URL','');
        $wx_payment_info['cert_path']  = env('PAYMENT_WX_TYPE', 'APPLET') == 'APPLET'?app_path().'/Tool/WeiXin/WxPay_Key/apiclient_cert.pem':app_path().'/Tool/WeiXin/WxPay_Key/tiger/apiclient_cert.pem';
        $wx_payment_info['key_path']   = env('PAYMENT_WX_TYPE', 'APPLET') == 'APPLET'?app_path().'/Tool/WeiXin/WxPay_Key/apiclient_key.pem':app_path().'/Tool/WeiXin/WxPay_Key/tiger/apiclient_key.pem';
        $wx_payment_info['ca_path']    = env('PAYMENT_WX_TYPE', 'APPLET') == 'APPLET'?app_path().'/Tool/WeiXin/WxPay_Key/cert/rootca.pem':app_path().'/Tool/WeiXin/WxPay_Key/tiger/cert/rootca.pem';
        if (empty($wx_payment_info['wx_appid']) || empty($wx_payment_info['wx_mchid']) || empty($wx_payment_info['pay_sign_key'])) {
            throw new \Exception('微信支付配置不正确'); 
        }
        $this->payConfig = $wx_payment_info;
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
     * [WxJsApiPay 微信内浏览器支付]
     * @author Edge
     * @time 2017/10/15
     * @param  array  $orderData [description]
     */
	public function payment($orderData = [])
    {
        $wxPayHelper = new \WxPayHelper();

        $wxPayHelper->setParameter("mch_appid", $this->payConfig['wxappid']);			//公众账号appid
        $wxPayHelper->setParameter("mchid", $this->payConfig['wxmchid']);				//商户号
        $wxPayHelper->setParameter("device_info", '');									//设备号
        $wxPayHelper->setParameter("nonce_str", $this->great_rand());					//随机字符串
        $wxPayHelper->setParameter("partner_trade_no", $orderData['partner_trade_no']); //商户订单号
        $wxPayHelper->setParameter("openid", $orderData['openid']);						//用户openid
        $wxPayHelper->setParameter("check_name", 'NO_CHECK');		// NO_CHECK：不校验真实姓名, FORCE_CHECK：强校验真实姓名
        $wxPayHelper->setParameter("re_user_name", '自客');			// 如果 check_name 设置为FORCE_CHECK，则必填用户真实姓名
        $wxPayHelper->setParameter("amount", $orderData['amount']); //金额
        $wxPayHelper->setParameter("desc", $orderData['desc']);		// 企业付款操作说明信息。必填
        $wxPayHelper->setParameter("spbill_create_ip", '120.24.16.222');				//Ip地址
        error_log(date("Y-m-d H:i:s").json_encode($this->payConfig),3, "/tmp/audit_pay.log");
        $postXml = $wxPayHelper->create_pay_xml($this->payConfig['wxpaysignkey']);
        error_log(date("Y-m-d H:i:s").json_encode($postXml),3, "/tmp/audit_pay.log");

        $url = 'https://api.mch.weixin.qq.com/mmpaymkttransfers/promotion/transfers';
        $responseXml = $wxPayHelper->curl_post_ssl($url, $postXml, $this->payConfig);
        error_log(date("Y-m-d H:i:s").json_encode($responseXml),3, "/tmp/audit_pay.log");
        $responseObj = simplexml_load_string($responseXml, 'SimpleXMLElement', LIBXML_NOCDATA);
        error_log(date("Y-m-d H:i:s").json_encode($responseObj),3, "/tmp/audit_pay.log");
        $code = (array)$responseObj->return_code;
        $msg = (array)$responseObj->return_msg;
        $result_code = (array)$responseObj->result_code;
        $data['code'] = $code[0]; //状态码
        $data['msg'] = $msg[0];	  //提示
        $data['result_code'] = $result_code[0];	  //提示

        $save = array();
        $save['pay_result'] = json_encode($responseObj);
        //操作成功
        if($data['code'] == "SUCCESS" && $data['result_code'] == "SUCCESS"){
            $save['is_handle']   = 2;
        }else{
            $save['is_handle']   = 3;
        }
    }

    /**
     * 生成随机数
     *
     */
    private function great_rand(){
        $str = '1234567890abcdefghijklmnopqrstuvwxyz';
        $t1 = "";
        for($i=0;$i<30;$i++){
            $j=rand(0,35);
            $t1 .= $str[$j];
        }
        return $t1;
    }
}
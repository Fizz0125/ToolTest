<?php
/**
 * 微信支付回调
 * @author: edge
 * @time: 2017/10/20
 */
namespace Someline\Tool\WeiXin;
use Someline\Services\KnowledgeOrdersService;
use Someline\Models\Common\PublicFollow;
use Someline\Models\Common\WeixinPayLog;

require_once "WxpayAPI_php_v3.0.1/lib/WxPay.Api.php";
require_once "WxpayAPI_php_v3.0.1/lib/WxPay.Notify.php";

class WeiXinNotify extends \WxPayNotify
{

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
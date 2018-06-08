<?php
/**
* 微信付款类
* @author xianyq
*/
require_once("CommonUtil.php");
require_once("SDKRuntimeException.class.php");
require_once("MD5SignUtil.php");
class WxPayHelper
{
	var $parameters; //cft 参数
	
	function __construct()
	{
		
	}
	
	function setParameter($parameter, $parameterValue) {
		$this->parameters[CommonUtil::trimString($parameter)] = CommonUtil::trimString($parameterValue);
	}
	
	function getParameter($parameter) {
		return $this->parameters[$parameter];
	}
	
	function check_sign_parameters(){
		if($this->parameters["mch_appid"] == null || $this->parameters["mchid"] == null || $this->parameters["partner_trade_no"] == null || $this->parameters["openid"] == null || $this->parameters["amount"] == null || $this->parameters["desc"] == null || $this->parameters["spbill_create_ip"] == null){
			return false;
		} 	
		return true;
	}
	/**
	  例如：
	 	appid：    wxd111665abv58f4f
		mch_id：    10000100
		device_info：  1000
		Body：    test
		nonce_str：  ibuaiVcKdpRxkhJA
		第一步：对参数按照 key=value 的格式，并按照参数名 ASCII 字典序排序如下：
		stringA="appid=wxd930ea5d5a258f4f&body=test&device_info=1000&mch_i
		d=10000100&nonce_str=ibuaiVcKdpRxkhJA";
		第二步：拼接支付密钥：
		stringSignTemp="stringA&key=192006250b4c09247ec02edce69f6a2d"
		sign=MD5(stringSignTemp).toUpperCase()="9A0A8659F005D6984697E2CA0A
		9CF3B7"
	 */
	protected function get_sign($key){
		try {
			if (null == $key || "" == $key ) {
				throw new SDKRuntimeException("密钥不能为空！" . "<br>");
			}
			if($this->check_sign_parameters() == false) {   //检查生成签名参数
			   throw new SDKRuntimeException("生成签名参数缺失！" . "<br>");
		    }
			$commonUtil = new CommonUtil();
			ksort($this->parameters);
			$unSignParaString = $commonUtil->formatQueryParaMap($this->parameters, false);

			$md5SignUtil = new MD5SignUtil();
			return $md5SignUtil->sign($unSignParaString,$commonUtil->trimString($key));
		}catch (SDKRuntimeException $e)
		{
			die($e->errorMessage());
		}

	}
	
	function create_pay_xml($key, $retcode = 0, $reterrmsg = "ok"){
		try {
		    $this->setParameter('sign', $this->get_sign($key));
		    $commonUtil = new CommonUtil();
			
		    return  $commonUtil->arrayToXml($this->parameters);
		   
		}catch (SDKRuntimeException $e)
		{
			die($e->errorMessage());
		}		

	}
	
	function curl_post_ssl($url, $vars, $config, $second=30,$aHeader=array())
	{
		$ch = curl_init();
		//超时时间
		curl_setopt($ch,CURLOPT_TIMEOUT,$second);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER, 1);
		//这里设置代理，如果有的话
		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
		curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,false);
		
		//cert 与 key 分别属于两个.pem文件
		curl_setopt($ch,CURLOPT_SSLCERT,$config['cert_path']);
 		curl_setopt($ch,CURLOPT_SSLKEY,$config['key_path']);
 		curl_setopt($ch,CURLOPT_CAINFO,$config['ca_path']);

	 
		if( count($aHeader) >= 1 ){
			curl_setopt($ch, CURLOPT_HTTPHEADER, $aHeader);
		}
	 
		curl_setopt($ch,CURLOPT_POST, 1);
		curl_setopt($ch,CURLOPT_POSTFIELDS,$vars);
		$data = curl_exec($ch);
		if($data){
			curl_close($ch);
			return $data;
		}
		else { 
			$error = curl_errno($ch);
			echo "call faild, errorCode:$error\n"; 
			curl_close($ch);
			return false;
		}
	}


}

?>
<?php
/**
 * Openssl加密解密
 * 公钥加密码，私钥解密
 * @author: edge
 * @time: 2017/10/22
 */
namespace Someline\Tool\Openssl;

class Openssl
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
	 * [$rsa_private_key 移钥]
	 * @var string
	 */
	public $rsa_private_key = '';

	/**
	 * [$rsa_public_key 公钥]
	 * @var string
	 */
	public $rsa_public_key = '';

	/**
     * [__construct description]
     * @author: edge
	 * @time: 2017/10/22
     */
    public function __construct()
    {
    	$this->rsa_private_key_path = __DIR__ . '/key/rsa_private_key.pem';
    	$this->rsa_public_key_path = __DIR__ . '/key/rsa_public_key.pem';
    	if (!file_exists($this->rsa_private_key_path)) {
    		throw new \Exception('私钥不存在');
    	}
    	if (!file_exists($this->rsa_public_key_path)) {
    		throw new \Exception('公钥不存在');
    	}
    	if(!extension_loaded('openssl')){
    		throw new \Exception('缺少openssl扩展');
    	}
    	$this->rsa_private_key = openssl_pkey_get_private(file_get_contents($this->rsa_private_key_path));
    	$this->rsa_public_key = openssl_pkey_get_public(file_get_contents($this->rsa_public_key_path));
    	if (!$this->rsa_private_key) {
    		throw new \Exception('私钥不可用');
    	}
    	if (!$this->rsa_public_key) {
    		throw new \Exception('公钥不可用');
    	}
    }

	/**
     * 单例
     * @author: edge
     * @time: 2017/10/22
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
     * [Base64Encrypt 公钥加密]
     * @author: edge
 	 * @time: 2017/10/22
     * @param string $str [description]
     */
    public function Base64Encrypt($str = '')
    {
    	if (!empty($str)) {
    		$str = json_encode($str);
    		if(openssl_public_encrypt($str,$sign,$this->rsa_public_key)){
    			return base64_encode($sign);
    		} else {
    			throw new \Exception('加密数据出错');
    		}
    	} else {
    		throw new \Exception('要加密的原始数据为空');
    	}
    }

    /**
     * [Base64Decrypt 私钥解密]
     * @author: edge
 	 * @time: 2017/10/22
     * @param string $str [description]
     */
    public function Base64Decrypt($str = '')
    {
    	if (!empty($str)) {
    		$str = base64_decode($str);
    		if(openssl_private_decrypt($str,$design,$this->rsa_private_key)){
    			$design = json_decode($design,1);
    			return $design;
    		} else {
    			throw new \Exception('解密数据出错');
    		}
    	} else {
    		throw new \Exception('要解密的数据为空');
    	}
    }

}
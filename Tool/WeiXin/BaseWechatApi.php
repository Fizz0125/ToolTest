<?php
/**
 * 微信API基类
 * @author: Fizz
 * @time: 2018.05.18
 */
namespace Someline\Tool\WeiXin;

abstract class BaseWechatApi
{
    //token存储时间
    private $_token_expire = 7200;

    /**
     * BaseWechatApi constructor.
     */
    public function __construct()
    {
        $this->_token = env('WAP_WX_TOKEN', 'ziwork');
        // redis
        $this->redis = new CacheRedis();
        $this->env = config('localsystems.env') == 'pro'?true:false;//环境
    }

    /**
     * getAccessToken 获取token
     * @param bool $refresh
     * @return string
     * @author Fizz
     * @time 2018.05.18
     */
    private function getAccessToken()
    {
        $access_token = '';
        $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$this->_appId}&secret={$this->_appSecret}";

        $res = curl($url);

        if($res){
            $res = json_decode($res, 1);
            if(!empty($res['access_token'])){
                $this->setToken($this->getCacheKey(),$res['access_token']);
                $access_token = $res['access_token'];
            }
        }

        return $access_token;
    }

    /**
     * setToken 设置token
     * @param string $key
     * @param string $value
     * @return bool
     * @author Fizz
     * @time 2018.05.18
     */
    private function setToken($key = '', $value = '')
    {
        $this->redis->setValue($key,$value,$this->_token_expire);
    }

    /**
     * getToken 获取token
     * @param string $key
     * @return string
     * @author Fizz
     * @time 2018.05.18
     */
    public function getToken($refresh = false) : string
    {
        $cacheKey = $this->getCacheKey();
        $token = $this->redis->getValue($cacheKey);

        if (!$refresh && !empty($cache)) {
            return $token;
        }

        $token = $this->getAccessToken();

        $this->setToken($cacheKey, $token, $this->_token_expire);

        return $token;
    }

    /**
     * refreshToken 刷新token
     * @return void
     * @author Fizz
     * @time 2018.05.18
     */
    public function refreshToken ()
    {
        return $this->getToken(true);
    }

    /**
     * getCacheKey 存储token的key
     * @return string
     * @author Fizz
     * @time 2018.05.18
     */
    protected function getCacheKey() : string
    {
        return $this->_token_key;
    }
}
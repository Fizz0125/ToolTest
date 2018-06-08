<?php
/**
 * IpyySms发送短信工具类
 * 北京创世华信科技有限公司 http://www.ipyy.cn/
 * @author: edge
 * @time: 2017/11/08
 */
namespace Someline\Tool;
use Illuminate\Http\Request;
use Someline\Models\Common\User;
use Illuminate\Support\Facades\Crypt;
use URL,DB;

class IpyySms
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
	 * [$send_url description]
	 * @var string
	 */
	public $send_url = '';

	/**
     * [__construct description]
     * @author: edge
	 * @time: 2017/11/08
     */
    public function __construct()
    {

    }

    /**
     * 单例
     * @author: edge
     * @time: 2017/11/08
     * @return class
     */
    public static function getInstance()
    {
        if(empty(static::$instance)){
            static::$instance = new static();
        }
        return static::$instance;
    }

    public function send($send_sms_request)
    {
    	
    }

}
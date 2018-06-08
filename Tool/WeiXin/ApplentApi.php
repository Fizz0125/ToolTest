<?php
/**
 * 小程序API
 * @author: edge
 * @time: 2017/10/26
 */
namespace Someline\Tool\WeiXin;

use Illuminate\Support\Facades\Log;
use Someline\Models\Common\{BeaconFormId, BeaconOpenid};
use Someline\Tool\CacheRedis;

require_once "Encrypted/wxBizDataCrypt.php";

class ApplentApi
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
	 * [$_appId description]
	 * @var string
	 */
	protected $_appId = '';

    /**
     * [$_applet_access_token description]
     * @var string
     */
    protected $_applet_access_token = null;

	/**
	 * [$_appSecret description]
	 * @var string
	 */
    protected $_appSecret = '';

    /**
     * @var string
     */
    protected $tokenKey = 'access_token';

    /**
     * [$_appSecret description]
     * @var string
     */
    protected $formPrefix = 'beacon_form_id_';

    /**
     * 公众号token key前缀
     * @var string
     */
    protected $_prefix_token_key = 'wx_token_Key_';

    /**
     * package_token key前缀
     * @var string
     */
    protected $_prefix_package_token_key = 'wx_package_token_Key_';

    /**
     * [$o_domain_str nginx转发的]
     * @var string
     */
    public $o_domain_str = 'zikeserver';

    /**
     * 微信的缓存时间是7200(秒), 由于会存在时间差, 缓存时间比微信那边要小
     * @var integer
     */
    protected $_expire = 7200;

    /**
     * 定时刷新token（五分钟）
     * @var integer
     */
    protected $_reciprocalTime = 300;

    /**
     * 定时刷新token（key）
     * @var integer
     */
    protected $_reciprocalKey = 'wx_applet_reciprocalTime';

    /**
     * 单例
     * @return [type] [description]
     */
    public static function getInstance()
    {
        if(empty(static::$instance)){
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * [$message_keys 发送消息必传参数
     * @var [type]
     */
    public $message_keys = ['touser','templateId','page','keyword'];

    /**
   	 * [__construct description]
   	 * @author: edge
 	 * @time: 2017/10/26
   	 */
    public function __construct()
    {
//        ob_end_clean();//去bom头
        $this->_appId     = config('localsystems.applet.appid');
        $this->_appSecret = config('localsystems.applet.secret');

        // 生产
        defined('NOW_TIME') || define('NOW_TIME', time());
        // redis
        $this->redis = new CacheRedis();
        $this->env = config('localsystems.env') == 'pro'?true:false;//环境
        $this->_applet_access_token = $this->_prefix_token_key.$this->_appId;//token
    }

   	/**
   	 * [setFileCache 设置缓存]
   	 * @author: edge
     * @time: 2017/10/26
   	 * @param string $key   [description]
   	 * @param string $value [description]
   	 */
    public function setFileCache($key = '', $value = '')
    {
    	$res = false;
    	if (!empty($key) && !empty($value)) {
            $tokenArray = array(
                $this->tokenKey => $value,
                'expires_in' => $this->_expire,
            );
            $this->redis->setValue($key,$value,$this->_expire);
            $this->redis->setValue($this->_prefix_package_token_key.$this->_appId,json_encode($tokenArray),$this->_expire);
            $this->redis->setValue($this->_reciprocalKey,$this->_reciprocalTime,$this->_reciprocalTime);

            $res = true;
    	}
    	return $res;
    }

    /**
     * [getFileCache 取缓存]
     * @author: edge
 	 * @time: 2017/10/26
     * @param  string $key [description]
     * @return [type]      [description]
     */
    public function getFileCache($key = '')
    {
    	$str = '';
    	if (!empty($key)) {
            $str = $this->redis->getValue($key);
        }
    	return $str;
    }

    /**
     * getSessionKey 获取session_key
     * @param $code 用code获取session_key
     * @return string
     * @author Fizz
     * @time 2017.12.8
     */
    public function getSessionKey($code = '')
    {
        if (empty($code)) {
            return false;
        }

        $url = 'https://api.weixin.qq.com/sns/jscode2session?appid='.$this->_appId.'&secret='.$this->_appSecret.'&js_code='.$code.'&grant_type=authorization_code';

        $arrContextOptions=array(
            "ssl"=>array(
                "verify_peer"=>false,
                "verify_peer_name"=>false,
            ),
        );

        $res = file_get_contents($url,false,stream_context_create($arrContextOptions));

//        $res = curl($url);
        $ress = json_decode($res, true);

        if (!empty($ress)) {
            return $ress;
        }
        return false;
    }

    /**
     * encrypted 解密数据
     * @param $encryptedData 需要解密的数据
     * @param $iv 加密算法的初始向量
     * @return mixed
     * @author Fizz
     * @time 2017.12.8
     */
    public function encrypteds ($encryptedData, $iv, $sessionKey)
    {
        $pc = new \WXBizDataCrypt($this->_appId, $sessionKey);
        $errCode = $pc->decryptData($encryptedData, $iv, $data);

        if ($errCode == 0) {//成功
            $data_info = json_decode($data, true);
            $return['res'] = $data_info;
        } else {//失败
            $return['res'] = false;
            $return['errCode'] = $errCode;
        }
        return $return;
    }

    /**
     * 得到公众号下接口调用的token
     * Function getAccessToken
     * User: Fizz
     * Date: 2017.12.12
     * Time: 14:00
     */
    public function getAccessToken($refresh = false){
        $key = $this->_applet_access_token;
        $access_token = $this->getFileCache($key);
        if (!$this->redis->getValue($this->_reciprocalKey)) {
            $refresh = true;
        }
        if(!$access_token || $refresh){
            $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$this->_appId}&secret={$this->_appSecret}";

            $res = file_get_contents($url);

            if($res){
                $res = json_decode($res, 1);
                if(!empty($res['access_token'])){
                    $this->setFileCache($key,$res['access_token']);
                    $access_token = $res['access_token'];
                }
            }
        }
        return $access_token;
    }

    /**
     * createwxaqrcode 生成小程序二维码
     * @param $path 需要在 app.json 的 pages 中定义
     * @param int $with 二维码的宽度
     * @return void
     * @author Fizz
     * @time 2017.12.12
     */
    public function createwxaqrcode($data = array())
    {
        $accessToken = $this->getAccessToken();
        $res = '';
        if (!empty($accessToken)) {
            switch($data['type']){
                case 'A'://获取小程序码,适用于需要的码数量较少的业务场景 接口地址
                    $url = "https://api.weixin.qq.com/wxa/getwxacode?access_token={$accessToken}";

                    $post_data = array(
                        'path'  => $data['page']??'',
                        'width' => $data['width']??430,
                    );
                    break;
                case 'B'://获取小程序码,适用于需要的码数量极多，或仅临时使用的业务场景
                    $url = "https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token={$accessToken}";

                    $post_data = array(
                        'scene' => $data['scene']??'AAA',
                        'page'  => $data['page']??'',
                        'width' => $data['width']??430
                    );
                    break;
                default://获取小程序二维码
                    $url = "https://api.weixin.qq.com/cgi-bin/wxaapp/createwxaqrcode?access_token={$accessToken}";

                    $post_data = array(
                        'path'  => $data['page']??'',
                        'width' => $data['width']??430
                    );
                    break;
            }

            $post_data = json_encode($post_data);

            $res = curl($url,$post_data);
            
            return $res;
            //header('Content-Type: image/jpeg');
            //echo $res;exit;
        }
    }

    /**
     * sendMessage 发送服务通知
     * @param array $data
     * @return bool
     * @author Fizz
     * @time 2018.05.09
     */
    public function sendMessage($data = array(), $beacon_send_message_model)
    {
        try {
            if (!is_array($data)) throw new \Exception();
            foreach ($this->message_keys as $b) {
                if (!isset($data[$b])) {
                    $this->error = $b.'参数必传';
                    return false;
                }
            }
            $openid = $data['touser'];
            $result = false;
            $formId = $this->getFormId($openid);

            //获取access_token
            $accessToken = $this->getAccessToken();
            $url = "https://api.weixin.qq.com/cgi-bin/message/wxopen/template/send?access_token={$accessToken}";

            $keyword = array();
            if (!empty($data['keyword']) && is_array($data['keyword'])) {
                foreach ($data['keyword'] as $k=>$v) {
                    $keyword['keyword'.($k+1)] = array(
                        "value" => $v
                    );
                }
            }

            $templateIdArray = $this->templateId($data['templateId']);
            $templateIds = $templateIdArray['templateId'];
            $title       = $templateIdArray['title'];

            $post_data = array(
                            'touser'      => $openid,
                            'template_id' => $templateIds,
                            'page'        => $data['page'],
                            'form_id'     => $formId,
                            'data'        => $keyword
                        );

            $post_data = json_encode($post_data);
            $res = curl($url,$post_data);
            error_log(date("Y-m-d H:i:s"). "---res---".json_encode($res).PHP_EOL,3, storage_path()."/logs/Template.log");

            if ($res) {
                $ress = json_decode($res, true);
                if(!empty($ress) && isset($ress['errcode']) && $ress['errcode'] == 0){
                    $result = true;
                }
            }

            //记录日志
            $logData = array(
                'touser'      => $openid,
                'template_id' => $templateIds,
                'title'       => $title,
                'page'        => $data['page']??'',
                'form_id'     => $formId,
                'data'        => $post_data,
                'json_data'   => $res,
                'color'       => $data['color']??'',
                'from'        => 1,
                'to'          => 1,//跳转到哪里，1小程序灯塔，2.h5灯塔
                'result'      => $result,
                'is'          => $data['is'],//后面（精选或者小程序）是否还可以发送
                'source'      => $data['source']//触发发送的端
            );
            WeiXinApi::getInstance()->recordSendTemplateLog($logData, $beacon_send_message_model);
        } catch (\Exception $e) {
            $e->getTraceAsString();
        } finally {
            return $result;
        }
    }

    /**
     * templateId 模板id & 模板标题
     * @param $id 对应一下数组模板的key
     * @return mixed
     * @author Fizz
     * @time 2018.05.14
     */
    private function templateId ($id)
    {
        if ($this->env) {//正式
            $templateIds = array(
                '_gDsYwT6fRyoFZRmsV9O0s_L3qN50uOl5DLD9LwBbSQ',//0加入免费塔成功通知
                'wYNEIAlqNGS9Z2eIzmUKPIV_fISOUOOGkF2DxF2wWag',//1免费集Call入社成功通知（已加入）
                'ZOun3n1za5oqjNd18-RQFjl5wMZKuQ1u5onMdEIfVvw',//2免费集Call入社失败通知（已加入）
                'wYNEIAlqNGS9Z2eIzmUKPIV_fISOUOOGkF2DxF2wWag',//3付费入社成功通知（已加入）
                'BQox6Sghv5O5zhheo_eU0l0lETRxxC7NwjbgaFPDxgM',//4免费提问/付费提问/追问/已过期付费提问已被回答通知
                'JE9EwooyQYqGNSXmN0xVrWLTs44-CYa9V7VIVS-0I4k',//5别人同意/拒绝我的交换微信申请
                'h8s5h9lzhof6GzxD_Pqem98Igj6UFO8mHB9wxY1Inis',//6分销佣金奖励
                'yqI-Yw9-b0fI8KLBZ0UU9iXEEF-aUVBB6vlJgHJYtJc',//7被交换微信通知
                'tFLAuuj6cm_1YmSPxcClyzprqyO_K21lxja8Q5PDLyc',//8付费提问过期未回答通知
                'ekJpA_iFy30aghkF-PrjxC6PN8E2JIrn1_SnS1dCVeA',//9集call未完成
                'S9TukXwGvn6WXRejvIB81EDeg9aEHvC0U_TjiwiwtIQ',//10开塔通知
                'JMDB-fDWNSAnNCV76VLIZSKfTl5loeNRazbu_PsHLHI',//11内容更新通知
                'I13D6wLB56pyitlQ7n83WX3bSdLPkE-cWzTnK8TtQco',//12灯塔上新
                'aW0yEzhypj4z2kJ2dJsrqcpBNyQHBCxoIuF0oC5OQ04',//13优惠券即将过期通知
                's9l3hzTZ56m_YqQ_zP0cDKtjli5VAcHDi2NJ_RNQdGo',//14帖子被回复（管理员和普通用户）/（塔主和嘉宾）
                'C7msgoAG9eMj5nVGIQCdBeSUqIi75GV8ixAWwz3IlWM',//15评论被回复
                'Kw-mqBlVvJNI4Suqb6KZvrmH2lqdBnSAddCVEsuzAeA',//16内容被点赞
                'kCp56p5Guf7eURg5Kxsv4ThvjpFfw_c7j489vwaArE0',//17问题超时未回复
                '',//18已下单且1小时内未产生成功订单
            );
        } else {//测试
            $templateIds = array(
                'IR3_f6-_WXAWFi1f7zBOHQ-vvI_CqkOwQvT4M0s0QBI',//0加入免费塔成功通知
                'gALaGkofGfVvt-eABFb5q6TAc7HJWRL2B07SqDGp0_Y',//1免费集Call入社成功通知（已加入）
                'm6rMl3w2BqtMi1XwbyEBeqElMHK8_f-itra6A8l_bzs',//2免费集Call入社失败通知（已加入）
                'gALaGkofGfVvt-eABFb5q6TAc7HJWRL2B07SqDGp0_Y',//3付费入社成功通知（已加入）
                'qI902WMPGM3OngLrnPi8M_Vc6kmcwaOa2ser_ZJr9P4',//4免费提问/付费提问/追问/已过期付费提问已被回答通知
                'gW0COvT1-ndhfWzs6_tIQd9I_yN-gtFPVUfZM7nYRnM',//5别人同意/拒绝我的交换微信申请
                'P3uMxTaJITfWtqQwtDXoja2ndwKlhTqNfcNKlAfxBTc',//6分销佣金奖励
                'NyuyAqvrIjbNPaKi6REvwRVRcMyFz6FdG_oW5YkFahI',//7被交换微信通知
                'iPBqUHS0NPBmeVbVEXYCllZaJEyyIPO13MlTqUvhdvw',//8付费提问过期未回答通知
                'gherlt6pOkMc0_VNE1q2sgH-nmjWGGUZywZrt056V9g',//9集call未完成
                'jmMQhMPIpLs74pcRzmR4LPHRYNWyuLZYloRixtlYkMU',//10开塔通知
                'xTgla5GhfaYs30YaE-ulWAI2YtZFp_lCWSjq0vpQaYM',//11内容更新通知
                'GcrppYFHbuDYHiMX9PqLfv2dLMKpoWAWCKRVxDCZFf0',//12灯塔上新
                '-RAw31IaOOMphBTYDNWNoKASiWI1uk39xzHlxrzc04s',//13优惠券即将过期通知
                'pIMISLVtv_Zim4QJ9qJaBNI6y6hIV_z4wI7hdyD0bZY',//14帖子被回复（管理员和普通用户）/（塔主和嘉宾）
                'XCREzY10m_u9qO-tSNtXI-SXWgxPGpBCU-lLyUoBnms',//15评论被回复
                'RGHabI42wx7xLGgxsqnietoNRgPx7Gyje5aVx1VFVKo',//16内容被点赞
                'ceKKmYNv612BDTKimLoZmA_8DoScoHcCkk2sQFSce1Q',//17问题超时未回复
                '',//18已下单且1小时内未产生成功订单
            );
        }

        $title = array(
            '加入免费塔成功通知',
            '免费集Call入社成功通知（已加入）',
            '免费集Call入社失败通知（已加入）',
            '付费入社成功通知（已加入）',
            '免费提问/付费提问/追问/已过期付费提问已被回答通知',
            '别人同意/拒绝我的交换微信申请',
            '分销佣金奖励',
            '被交换微信通知',
            '付费提问过期未回答通知',
            '集call未完成',
            '开塔通知',
            '内容更新通知',
            '灯塔上新',
            '优惠券即将过期通知',
            '帖子被回复（管理员和普通用户）/（塔主和嘉宾）',
            '评论被回复',
            '内容被点赞',
            '问题超时未回复',
            '已下单且1小时内未产生成功订单'
        );

        if (count($title) < $id + 1) {
            $data = array(
                'title'      => '错误模板id'.$id,
                'templateId' => $id
            );
        } else {
            $data = array(
                'title'      => $title[$id],
                'templateId' => $templateIds[$id]
            );
        }

        return $data;
    }

    /**
     * getFormId 获取formid
     * @param $openid 用户的openid
     * @return string
     * @author Fizz
     * @time 2018.05.14
     */
    private function getFormId ($openid)
    {
        $sevenDays = strtotime('-6 day');//七天内
        $formId = '';

        $formidInfo = BeaconOpenid::where('openid', $openid)->first();
        if ($formidInfo) {
            $formIdModel = $formidInfo->formId()->where('status', 1)->where('create_time', '>', $sevenDays)->first();
            if ($formIdModel) {
                $formId = $formIdModel->form_id;
                BeaconFormId::where('form_id', $formId)->update(['status' => 2]);
            }
        }
        $key = $this->formPrefix.$openid;
        if (empty($formId)) {
            $hashKey = $this->redis->hKeys($key);
            foreach ($hashKey as $a => $b) {
                if (intdiv($b, 100000) < $sevenDays) {//七天前
                    $this->redis->hDel($key, $b);
                } else {
                    $formId = $this->redis->hGet($key, $b);
                    $this->redis->hDel($key, $b);
                    break;
                }
            }
        }

        return $formId;
    }

    /**
     * setFormId 存储formId
     * @param $openid
     * @param $fromId
     * @return void
     * @author Fizz
     * @time 2018.
     */
    public function setFormId ($openid, $fromId)
    {
        $this->redis->hSet($this->formPrefix.$openid, time().rand(11111,99999), $fromId);
    }
}
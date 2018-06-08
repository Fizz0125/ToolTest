<?php
/**
 * 微信API(精选)
 * @author: Fizz
 * @time: 2018.03.21
 */
namespace Someline\Tool\WeiXin;

use Someline\Jobs\Wechat\Selected\{SendCustomMessageSelected, subscribeAutoRegister};
use Someline\Models\Common\{BeaconEventTemplateSend, WechatMediaId, WechatSendMessage, WechatSendMessageLog};
use Someline\Services\h5\{WXSelectedService, BeaconService};
use Someline\Tool\CacheRedis;

class WXSelectApi
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
	 * [$_appSecret description]
	 * @var string
	 */
    protected $_appSecret = '';

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
    protected $_reciprocalKey = 'wx_selected_reciprocalTime';

    /**
     * 填写的URL需要正确响应微信发送的Token验证
     * @var string
     */
    protected $_token = '';

    /**
     * 公众号token
     * @var string
     */
    protected $_selected_token = '';

    /**
     * 公众号token key前缀
     * @var string
     */
    protected $_prefix_token_key = 'wx_token_Key_';

    /**
     * 公众号网页授权token
     * @var string
     */
    protected $_selected_oauth_token = '';

    /**
     * jsapi_ticket
     * @var string
     */
    protected $_jsapi_ticket = '';

    /**
     * [$message_keys 发送消息必传参数
     * @var [type]
     */
    public $message_keys = ['touser','templateId','data'];

    /**
     * [$message_keys 发送消息必传参数
     * @var [type]
     */
    public $env = false;//生成环境

   	/**
   	 * 回复文本模板
   	 * @var string
   	 */
    protected $_textTpl = "<xml>
							<ToUserName><![CDATA[%s]]></ToUserName>
							<FromUserName><![CDATA[%s]]></FromUserName>
							<CreateTime>%s</CreateTime>
							<MsgType><![CDATA[text]]></MsgType>
							<Content><![CDATA[%s]]></Content>
							</xml>";

    /**
   	 * 回复图片模版
   	 * @var string
   	 */
    protected $_imageTpl = "<xml>
                            <ToUserName><![CDATA[%s]]></ToUserName>
                            <FromUserName><![CDATA[%s]]></FromUserName>
                            <CreateTime>%s</CreateTime>
                            <MsgType><![CDATA[image]]></MsgType>
                            <Image>
                            <MediaId><![CDATA[%s]]></MediaId>
                            </Image>
                            </xml>";

    /**
   	 * 回复语音模板
   	 * @var string
   	 */
    protected $_voiceTpl = "<xml>
                            <ToUserName><![CDATA[%s]]></ToUserName>
                            <FromUserName><![CDATA[%s]]></FromUserName>
                            <CreateTime>%s</CreateTime>
                            <MsgType><![CDATA[voice]]></MsgType>
                            <Voice>
                            <MediaId><![CDATA[%s]]></MediaId>
                            </Voice>
                            </xml>";

    /**
   	 * 回复图文模板
   	 * @var string
   	 */
    protected $_newsTpl = "<xml>
                            <ToUserName><![CDATA[%s]]></ToUserName>
                            <FromUserName><![CDATA[%s]]></FromUserName>
                            <CreateTime>%s</CreateTime>
                            <MsgType><![CDATA[news]]></MsgType>
                            <ArticleCount>%s</ArticleCount>
                            <Articles>%s</Articles>
                            </xml>";
    /**
   	 * @var string
   	 */         
    protected $_itemTpl = "<item>
                            <Title><![CDATA[%s]]></Title>
                            <Description><![CDATA[%s]]></Description>
                            <PicUrl><![CDATA[%s]]></PicUrl>
                            <Url><![CDATA[%s]]></Url>
                            </item>";

    /**
     * [$o_domain_str nginx转发的]
     * @var string
     */
    public $o_domain_str = 'zikeserver';

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
   	 * [__construct description]
   	 * @author: edge
 	 * @time: 2017/10/26
   	 */
    public function __construct()
    {
    	// 生产
    	$this->isTest = false;
        $this->_appId     = env('SELECTED_WX_APPID');
        $this->_appSecret = env('SELECTED_WX_APPSECRET');
        $this->_token = env('SELECTED_WX_TOKEN', 'ziwork');
    	defined('NOW_TIME') || define('NOW_TIME', time());
        // redis
        $this->redis = new CacheRedis();
        $this->env = config('localsystems.env') == 'pro'?true:false;//环境

        $this->_selected_token = $this->_prefix_token_key.$this->_appId;//基础token
        $this->_selected_oauth_token = 'wx_selected_oauth_access_token';//网页授权token
        $this->_jsapi_ticket = 'wx_selected_jsapi_ticket';//jsapi_ticket
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
            // $res = cache([$key => $value], 100);//从获取的时候开始算，存少于两个小时
            $res = $this->redis->setValue($key,$value,$this->_expire);
            if ($key == $this->_selected_token) {
                $this->redis->setValue($this->_reciprocalKey,$this->_reciprocalTime,$this->_reciprocalTime);
            }
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
            // if (Cache::has($key)) {
            //     $str = cache($key);//从获取的时候开始算，存一天
            // }
            $str = $this->redis->getValue($key);
    	}
    	return $str;
    }

    /**
     * 得到公众号下接口调用的token
     * Function getAccessToken
     * User: edge
     * Date: 2017/10/26
     * Time: 14:00
     */
    public function getAccessToken($refresh = false){
        $key = $this->_selected_token;
        $access_token = $this->getFileCache($key);
        error_log(date("Y-m-d H:i:s"). "---access_token---".$access_token.PHP_EOL,3, storage_path()."/logs/access_token.log");

        if (!$this->redis->getValue($this->_reciprocalKey)) {
            $refresh = true;
        }
        if(empty($access_token) || $refresh){
            $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$this->_appId}&secret={$this->_appSecret}";
            error_log(date("Y-m-d H:i:s"). "---url---".$url.PHP_EOL,3, storage_path()."/logs/access_token.log");

            if($this->isTest){
                //本地
                $res = file_get_contents($url);
            }else{
                $res = curl($url);
            }
            if($res){
                $res = json_decode($res, 1);
                if(!empty($res['access_token'])){
                    $this->setFileCache($key,$res['access_token']);
                    $access_token = $res['access_token'];
                }
            }
        }
        error_log(date("Y-m-d H:i:s"). "---access_tokens---".$access_token.PHP_EOL,3, storage_path()."/logs/access_token.log");

        return $access_token;
    }

    /**
     * 微信OAuth2.0网页授权认证 网页授权获取用户基本信息功能
     * 得到CODE值(貌似不能通过CURL拿CODE值)
     * Function getOauthCodeUrl
     * User: edgeto
     * Date: 2017/10/26
     * Time: 14:00
     */
    public function getOauthCodeUrl($params=array()){
        $redirect_url = empty($params['redirect_url']) ? 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'] : $params['redirect_url'];
        $scope = empty($params['scope']) ? 'snsapi_base' : $params['scope'];
        $state = random_str(8);
        $redirect_url = urlencode($redirect_url);
        return "https://open.weixin.qq.com/connect/oauth2/authorize?appid={$this->_appId}&redirect_uri={$redirect_url}&response_type=code&scope={$scope}&state={$state}#wechat_redirect";
    }

    /**
     * 微信公众平台开发OAuth2.0网页授权认证 网页授权获取用户基本信息功能
     * 网页授权access_token,与基础支持中的access_token（该access_token用于调用其他接口）不同
     * User: edgeto
     * Date: 2017/10/26
     * Time: 14:00
     * @param string $code
     * @return mixed
     */
    public function getOauthAccessToken($code = ''){
        $key = $this->_selected_oauth_token;
        $access_token = $this->getFileCache($key);
        error_log(date("Y-m-d H:i:s"). "---access_token---".$access_token.PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");

        if(!$access_token || $code){
            $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$this->_appId}&secret={$this->_appSecret}&code={$code}&grant_type=authorization_code";
            error_log(date("Y-m-d H:i:s"). "---url---".$url.PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");

            if($this->isTest){
                //本地
                $res = file_get_contents($url);
            }else{
                $res = curl($url);
            }
            if($res){
                $res = json_decode($res, 1);
                if(!empty($res['access_token'])){
                    $this->setFileCache($key.'_'.$res['openid'],$res['access_token']);
                    $access_token = $res['access_token'];
                }
                $access_token = $res;
            }
        }
        return $access_token;
    }

    /**
     * 获取OpenId
     * User: edgeto
     * Date: 2017/10/26
     * Time: 14:00
     * @param array $params
     * @return bool
     */
    public function getOpenId($params=array()){
        $state = request()->input('state');
        $code = request()->input('code');
        error_log(date("Y-m-d H:i:s"). "---state&code---".$state."/".$code.PHP_EOL,3, storage_path()."/logs/selected_code.log");

        if (empty($state) && empty($code)) {
            header('Location: '.$this->getOauthCodeUrl($params));
            die();
        }
        $oauth_access_token = $this->getOauthAccessToken($code);
        error_log(date("Y-m-d H:i:s"). "---oauth_access_token---".json_encode($oauth_access_token).PHP_EOL,3, storage_path()."/logs/selected_code.log");

        if (!empty($oauth_access_token['openid'])) {
            return $oauth_access_token['openid'];
        }
        return false;
    }

    /**
     * 微信公众平台开发OAuth2.0网页授权认证 网页授权获取用户基本信息
     * Function getOauthUserInfo
     * User: edgeto
     * Date: 2017/10/26
     * Time: 10:00
     */
    public function getOauthUserInfo($openid){
        $key = $this->_selected_oauth_token.'_'.$openid;
        $access_token = $this->getFileCache($key);
        $url = "https://api.weixin.qq.com/sns/userinfo?access_token={$access_token}&openid={$openid}&lang=zh_CN";

        if($this->isTest){
            //本地
            $res = file_get_contents($url);
        }else{
            $res = curl($url);
        }
        return json_decode($res, 1);
    }

     /**
     * 获取用户基本信息(UnionID机制) 与 网页授权获取用户基本信息不同
     * Function getOauthUserInfo
     * User: edgeto
     * Date: 2017/10/26
     * Time: 10:00
     */
    public function getOauthUserInfo_s($openid){
        error_log(date("Y-m-d H:i:s"). "---openidss---".$openid.PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");

        $access_token = $this->getAccessToken();
        if (empty($access_token)) {
            $access_token = $this->getAccessToken(1);
        }
//        $access_token = $this->getAccessToken(1);

        error_log(date("Y-m-d H:i:s"). "---access_token---".$access_token.PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");

        $url = "https://api.weixin.qq.com/cgi-bin/user/info?access_token={$access_token}&openid={$openid}&lang=zh_CN";
        error_log(date("Y-m-d H:i:s"). "---url---".$url.PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");

        if($this->isTest){
            //本地
            $res = file_get_contents($url);
        }else{
            $res = curl($url);
        }
        error_log(date("Y-m-d H:i:s"). "---res---".$res.PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");

        return json_decode($res, 1);
    }

    /**
     * 接收消息主程序
     * Function notify
     * User: edgeto
     * Date: 2017/10/26
     * Time: 10:00
     */
    public function notify(){
        error_log(date("Y-m-d H:i:s"). "---notify---\r\n",3, storage_path()."/logs/selected_subscribe.log");

        //首次接入
        $signature = request()->input('signature');
        $timestamp = request()->input('timestamp');
        $nonce = request()->input('nonce');
        $echoStr = request()->input('echostr');
        if(!empty($signature) && !empty($timestamp) && !empty($nonce) && !empty($echoStr)){
            $this->checkSignature();
        }
        $this->responseMsg();
    }

    /**
     * 验证消息的确来自微信服务器
     * Function checkSignature
     * User: edgeto
     * Date: 2017/10/26
     * Time: 10:00
     */
    protected function checkSignature(){
        $signature = request()->input('signature');
        $timestamp = request()->input('timestamp');
        $nonce = request()->input('nonce');
        $echoStr = request()->input('echostr');
        $token = $this->_token;
        $tmpArr = array($token, $timestamp, $nonce);
        sort($tmpArr, SORT_STRING);
        $tmpStr = implode( $tmpArr );
        $tmpStr = sha1( $tmpStr );
       /* error_log(serialize(I('get.'))."\n",3,'/tmp/weixinnotify.log');
        error_log(serialize($tmpStr)."\n",3,'/tmp/weixinnotify.log');
        error_log(serialize($tmpStr == $signature)."\n",3,'/tmp/weixinnotify.log');
        error_log(serialize($echoStr)."\n",3,'/tmp/weixinnotify.log');*/
        if( $tmpStr == $signature ){
            echo $echoStr;exit;
        }else{
            echo 'wrong';exit;
        }
    }

    /**
     * 微信自动回复
     * Function responseMsg
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     */
    public function responseMsg(){
        error_log(date("Y-m-d H:i:s"). "---responseMsg---\r\n",3, storage_path()."/logs/selected_subscribe.log");

        $postStr = isset($GLOBALS["HTTP_RAW_POST_DATA"]) ? $GLOBALS["HTTP_RAW_POST_DATA"] : '';
        if (empty($postStr)){
            $postStr = file_get_contents("php://input");
        }
        error_log(date("Y-m-d H:i:s")."\n".serialize($postStr)."\n",3,'/tmp/selected_weixinnotify.log');
        if(!empty($postStr)){
            libxml_disable_entity_loader(true);
            $postObj = simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);

            $msgType = trim($postObj->MsgType);
            switch($msgType){
                case 'text';
                    $this->_response_text($postObj);
                    break;
                case 'image';
                    //$this->_response_image($postObj);
//                    $this->_response_news($postObj);
                    break;
                case 'voice';
//                    $this->_response_voice($postObj);
                    break;
                case 'event';
                    $this->_response_event($postObj);
                    break;

            }
        }
    }

    /**
     * 语音回复
     * Function _response_voice
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     * @param $postObj
     */
    public function _response_voice($postObj){
        $fromUsername = $postObj->FromUserName;
        $toUsername = $postObj->ToUserName;
        $MediaId = $postObj->MediaId;//语音内容
        $time = NOW_TIME;
        $resultStr = sprintf($this->_voiceTpl, $fromUsername, $toUsername, $time, $MediaId);
        echo $resultStr;exit;
    }

    /**
     * 图文回复
     * Function _response_news
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     * @param $postObj
     */
    public function _response_news($postObj){
        $fromUsername = $postObj->FromUserName;
        $toUsername = $postObj->ToUserName;
        $time = NOW_TIME;
        $ArticleCount = 1;//图文消息个数，限制为10条以内
        $MediaId = $postObj->MediaId;//图片
        //单个
        $title = '测试标题';//图文消息标题
        $desc = '测试描述';//图文消息描述
        $PicUrl = $postObj->PicUrl;//图片链接，支持JPG、PNG格式，较好的效果为大图360*200，小图200*200
        $Url = "http://www.baidu.com";//点击图文消息跳转链接
        $itemTpl = sprintf($this->_itemTpl, $title, $desc, $PicUrl, $Url);
        $resultStr = sprintf($this->_newsTpl, $fromUsername, $toUsername, $time, $ArticleCount,$itemTpl);
        echo $resultStr;exit;
    }

    /**
     * 图片回复
     * Function _response_image
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     * @param $postObj
     */
    public function _response_image($postObj, $media_id){//
        if (is_array($postObj)) {
            $fromUsername = $postObj['openid'];
            $toUsername = $postObj['toUser'];
        } else {
            $fromUsername = $postObj->FromUserName;
            $toUsername = $postObj->ToUserName;
        }
        $MediaId = $media_id;//图片
        error_log(date("Y-m-d H:i:s"). "---output---".$MediaId.PHP_EOL,3, storage_path()."/logs/subscribe.log");

        $time = NOW_TIME;
        $resultStr = sprintf($this->_imageTpl, $fromUsername, $toUsername, $time, $MediaId);
        echo $resultStr;exit;
    }

    /**
     * 文本回复
     * Function _response_text
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     * @param $postObj
     */
    public function _response_text($postObj)
    {
        $openid = $postObj->FromUserName;
        $content = $postObj->Content;

        $wechatMediaIdInfo = WechatSendMessage::where([
                                ['appid', '=', $this->_appId],
                                ['scene', '=', 3],//场景值，1扫带参二维码，2普通关注 ，3关键字回复
                                ['eventKey', 'like', '%'.$content.'%']
                            ])->orderBy('sort')->get();
        error_log(date("Y-m-d H:i:s"). "---wechatMediaIdInfo---".$wechatMediaIdInfo.PHP_EOL,3, storage_path()."/logs/responseText.log");

        foreach ($wechatMediaIdInfo as $v) {
            $msgtype   = $v->msgtype;
            $contents  = $v->content;
            $post_data = '{"touser":"' .$openid. '","msgtype":';
            switch($msgtype){
                case 'text';//文字
                    $post_data .= '"text","text":{"content":"' . $contents . '"}}';
                    break;
                case 'image';//图片
                    $post_data .= '"image","image":{"media_id":"' . $contents . '"}}';
                    break;
                case 'voice';//语音
                    $post_data .= '"voice","voice":{"media_id":"' . $contents . '"}}';
                    break;
                case 'video';//视频
//                    $post_data .= '"voice","video":{"media_id":"' . $contents . '","thumb_media_id":"' . $contents . '",}}';
                    break;
            }
            error_log(date("Y-m-d H:i:s"). "---post_data---".$post_data.PHP_EOL,3, storage_path()."/logs/responseText.log");

            $contets = !empty($contents) ? $contents : (!empty($mediaId) ?: '');
            $sendData = array(
                'msgtype' => $msgtype,
                'openid'  => $openid,
                'scene'   => 2,//发送场景；1关注和识别带参数二维码,2回复关键字
                'contents'   => $contets,
                'postData'=> $post_data
            );
            $this->recordSendLog($sendData);
        }
    }

    /**
     * recordSendLog 发送并记录日志
     * @param array $data
     * @return void
     * @author Fizz
     * @time 2018.05.04
     */
    private function recordSendLog ($data = array())
    {
        //记录发送的日志
        $WechatSendMessageLogInfo = new WechatSendMessageLog();
        $WechatSendMessageLogInfo->msgtype = $data['msgtype'];
        $WechatSendMessageLogInfo->appid = $this->_appId;
        $WechatSendMessageLogInfo->openid = $data['openid'];
        $WechatSendMessageLogInfo->content = $data['contents'];
        $WechatSendMessageLogInfo->scene = $data['scene'];
        $WechatSendMessageLogInfo->saveOrFail();

        //获取access_token
        $accessToken = $this->getAccessToken();
        $url = "https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token={$accessToken}";
        error_log(date("Y-m-d H:i:s"). "---url---".$url.PHP_EOL,3, storage_path()."/logs/custom.log");

        $res = curl($url, $data['postData']);
        $ress = json_decode($res, true);
        $WechatSendMessageLogInfo->post_data = $res;
        $WechatSendMessageLogInfo->result = $ress['errcode'] == 0 ? 1 : 0;
        $WechatSendMessageLogInfo->saveOrFail();
    }

    /**
     * 接收事件推送
     * Function _response_event
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     * @param $postObj
     */
    public function _response_event($postObj){
        error_log(date("Y-m-d H:i:s"). "---_response_event---\r\n",3, storage_path()."/logs/selected_subscribe.log");

        $Event = $postObj->Event;
        switch($Event){
            case 'subscribe';//关注
                $this->_event_subscribe($postObj);
                break;
            case 'CLICK';//自定义菜单事件
                $this->_event_menu_click($postObj);
                break;
            case 'SCAN';//用户已关注时的事件推送
                $this->_event_scan($postObj);
                break;
            case 'TEMPLATESENDJOBFINISH';//模板消息推送事件
//                $this->_event_template_send($postObj);
                break;
        }
    }

    /**
     * 关注事件推送
     * Function _event_subscribe
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     * @param $postObj
     */
    public function _event_subscribe($postObj){
        error_log(date("Y-m-d H:i:s"). "---START_event_subscribe---".PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");

        $openid    = (string)$postObj->FromUserName;
        $eventKey = (string)$postObj->EventKey;
        error_log(date("Y-m-d H:i:s"). "---START_event_subscribe---".$eventKey.PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");

        $this->autoRegiseter($openid);
        error_log(date("Y-m-d H:i:s"). "---subscribeAutoRegister---".PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");

        $data = array(
            'openid'   => $openid,
            'EventKey' => (int)mb_substr($eventKey, 8),
        );
        $this->sendMessageType($data);
        echo 'SUCCESS';
        exit;

//        $this->sendMessageType($postObj);
        $contentStrs = '欢迎来到小灯塔，100万职场人都选择的互联网职场学习社区。

【热门课程】
<a href="https://www.ziwork.com/beaconweb/?#/introduce/64074da38681f864082708b9be959e08">每天10分钟玩转朋友圈，普通人也能月入10W+</a>

<a href="https://www.ziwork.com/beaconweb/?#/introduce/f3df7c727dbf6f9b200e5e89ca7cd67c">你有多久没投资自己？老路手把手教你用得上的商学课</a>

灯塔大额优惠券限时派发中，N多课程0元请你学
点击菜单栏“进入灯塔”，开启拜师之旅
↓↓↓';

        $fromUsername = $postObj->FromUserName;//关注人
        $toUsername = $postObj->ToUserName;//微信公众号
        $contentStr = $contentStrs;

        $time = NOW_TIME;
        $resultStr = sprintf($this->_textTpl, $fromUsername, $toUsername, $time, $contentStr);
        echo $resultStr;
        exit;
    }

    /**
     * 自定义菜单点击事件推送
     * Function _event_menu_click
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     * @param $postObj
     */
    public function _event_menu_click($postObj)
    {
        $openid   = (string)$postObj->FromUserName;
        $eventKey = (string)$postObj->EventKey;
        error_log(date("Y-m-d H:i:s"). "---START_event_subscribe---".$eventKey.PHP_EOL,3, storage_path()."/logs/subscribe.log");

        $data = array(
            'openid'   => $openid,
            'scene'    => 4,//1带参数二维码,2普通关注,3关键字回复,4自定义菜单key
            'EventKey' => $eventKey
        );
        error_log(date("Y-m-d H:i:s"). "---wechatMediaIdInfo---".json_encode($data).PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");

        $this->sendMessageType ($data);
        echo 'SUCCESS';
        exit;

        $openid   = $postObj->FromUserName;//关注人
        $EventKey = $postObj->EventKey;//时间key
        $send = 0;

        if ($EventKey == 'benke') {
            $contents = "【本科论文50元代金券】使用方法：
1、【电脑】登录知网查重官网： cnki6.cnkipmlc.checkpass.net      (复制到电脑端使用）2、输入【论文标题】、【作者】、【上传论文】
3、点击【提交检测】，打开支付宝扫码支付，自动抵扣50元
4、保存好订单号，凭订单号下载检测报告


 【温馨提示】
①代金券有效期为3天，过期了可以在公众号里再次领取。支付时请确保代金券在有效期内
②如何查看代金券：打开支付宝-【首页】-【卡包】-【券】
③本券不可兑换现金，不可找零
【24小时自助查重检测地址】 cnki6.cnkipmlc.checkpass.net      (复制到电脑端使用)(复制到电脑端使用)";
            $send = 1;
        } elseif ($EventKey == 'sobo') {
            $contents = "【硕博论文50元代金券】使用方法：
1、【电脑】登录知网查重官网：cnki6.cnkivip.checkpass.net;(复制到电脑端使用）
2、输入【论文标题】、【作者】、【上传论文】
3、点击【提交检测】，打开支付宝扫码支付，自动抵扣50元
4、保存好订单号，凭订单号下载检测报告

 【温馨提示】①代金券有效期为3天，过期了还可以在公众号里再次领取。支付时请确保代金券在有效期内
②如何查看代金券：打开支付宝-【首页】-【卡包】-【券】
③本券不可兑换现金，不可找零
【24小时自助查重检测地址】cnki6.cnkivip.checkpass.net(复制到电脑端使用)";
            $send = 1;
        }

        if ($send) {
            $post_data = '{"touser":"' .$openid. '","msgtype":"text","text":{"content":"' . $contents . '"}}';
            $contets = !empty($contents) ? $contents : (!empty($mediaId) ?: '');
            $sendData = array(
                'msgtype' => 'text',
                'openid'  => $openid,
                'scene'   => 3,//发送场景；1关注和识别带参数二维码,2回复关键字,3自定义菜单回复
                'contents'   => $contents,
                'postData'=> $post_data
            );
            $this->recordSendLog($sendData);
        }
    }

    /**
     * 用户已关注时的事件推送
     * Function _event_scan
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     * @param $postObj
     */
    public function _event_scan($postObj)
    {
        $openid   = (string)$postObj->FromUserName;
        $eventKey = (string)$postObj->EventKey;
        error_log(date("Y-m-d H:i:s"). "---START_event_subscribe---".$eventKey.PHP_EOL,3, storage_path()."/logs/subscribe.log");

        $data = array(
            'openid'   => $openid,
            'EventKey' => $eventKey
        );
        $this->sendMessageType ($data);
        echo 'SUCCESS';
        exit;
    }

    /**
     * 模板消息推送事件(用于记录日志)
     * Function _event_template_send
     * User: Fizz
     * Date: 2018.01.08
     * Time: 12:00
     * @param $postObj
     */
    public function _event_template_send($postObj)
    {
        try {//记录日志
            $BeaconEventTemplateSend = new BeaconEventTemplateSend();
            $BeaconEventTemplateSend->to_user_name         = $postObj->ToUserName;
            $BeaconEventTemplateSend->from_user_name       = $postObj->FromUserName;
            $BeaconEventTemplateSend->template_create_time = $postObj->CreateTime;
            $BeaconEventTemplateSend->msg_type             = $postObj->MsgType;
            $BeaconEventTemplateSend->Event                = $postObj->Event;
            $BeaconEventTemplateSend->msg_id               = $postObj->MsgID;
            $BeaconEventTemplateSend->status               = $postObj->Status;
            $BeaconEventTemplateSend->json_data            = $postObj;
            $BeaconEventTemplateSend->saveOrFail();
        } catch (\Exception $e) {

        }
    }

    /**
     * JS-JDK下调用时所需的另外一个ticket token
     * Function getJsApiTicket
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     * @return mixed
     */
    public function getJsApiTicket(){
        $key = $this->_jsapi_ticket;
        $jsapi_ticket = $this->getFileCache($key);
        if (empty($jsapi_ticket)) {
            $accessToken = $this->getAccessToken();
            if (empty($accessToken)) {
                $accessToken = $this->getAccessToken(1);
            }
            $url = "https://api.weixin.qq.com/cgi-bin/ticket/getticket?type=jsapi&access_token={$accessToken}";
            if($this->isTest){
                //本地
                $res = file_get_contents($url);
            }else{
                $res = curl($url);
            }
            if($res){
                $res = json_decode($res, 1);
                if(!empty($res['ticket'])){
                    $this->setFileCache($key,$res['ticket']);
                    $jsapi_ticket = $res['ticket'];
                }
            }sessions('teken', $res);
        }
        return $jsapi_ticket;
    }

    /**
     * JS-JDK下调用前的配置参数
     * Function getSign
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     * @return array
     */
    public function getSign($url = ''){
        $jsapiTicket = $this->getJsApiTicket();
//        $http = 'http://';
//        $url = $url ? $url : $http.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
        $url = $url ? $url : $_SERVER['HTTP_REFERER'];
        /*if(!empty($_SERVER['QUERY_STRING'])){
            $url .= '?'.$_SERVER['QUERY_STRING'];
        }*/
        $timestamp = NOW_TIME;
        $nonceStr = random_str(8);
        //按照 key 值ASCII码升序排序
        $plain = "jsapi_ticket=$jsapiTicket&noncestr=$nonceStr&timestamp=$timestamp&url=$url";
        $signature = sha1($plain);
        $signPackage = array(
            "appId"     => $this->_appId,
            "nonceStr"  => $nonceStr,
            "timestamp" => $timestamp,
            "url"       => $url,
            "signature" => $signature,
            "rawString" => $plain
        );
        return $signPackage;
    }

    /**
     * JS-JDK下初始化数据
     * Function genConfig
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     * @return string
     */
    public function genConfig(){
        $signs = $this->getSign();
        $config = <<<EOT
        wx.config({
            debug: false,
            appId: '{$signs['appId']}',
            timestamp: {$signs['timestamp']},
            nonceStr: '{$signs['nonceStr']}',
            signature: '{$signs['signature']}',
            jsApiList: [
                'checkJsApi',
                'onMenuShareTimeline',
                'onMenuShareAppMessage',
                'onMenuShareQQ',
                'onMenuShareWeibo',
                'hideMenuItems',
                'showMenuItems',
                'hideAllNonBaseMenuItem',
                'showAllNonBaseMenuItem',
                'translateVoice',
                'startRecord',
                'stopRecord',
                'onRecordEnd',
                'playVoice',
                'pauseVoice',
                'stopVoice',
                'uploadVoice',
                'downloadVoice',
                'chooseImage',
                'previewImage',
                'uploadImage',
                'downloadImage',
                'getNetworkType',
                'openLocation',
                'getLocation',
                'hideOptionMenu',
                'showOptionMenu',
                'closeWindow',
                'scanQRCode',
                'chooseWXPay',
                'openProductSpecificView',
                'addCard',
                'chooseCard',
                'openCard'
            ]
        });
EOT;
        return $config;
    }

    /**
     * 长链接转短链接接口
     * Function getShortUrl
     * User: edgeto
     * Date: 2017/10/26
     * Time: 12:00
     * @param string $long_url
     */
    public function getShortUrl($long_url = ''){
        $http = 'http://';
        $long_url = $long_url ? $long_url :  $http.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
        $_url = input('url');
        $long_url = $_url ? $_url :$long_url;
        $access_token = $this->getAccessToken();
        $url = "https://api.weixin.qq.com/cgi-bin/shorturl?access_token={$access_token}";
        $post_data = array(
            'action' => 'long2short',
            'long_url' => $long_url,
        );
        $post_data = json_encode($post_data);
        $res = curl($url,$post_data);
        if($res){
            $res = json_decode($res, 1);
        }
        dump($res);exit;
    }

    public function test(){
        $openId = $this->getOpenId();
        $userInfo_s = $this->getOauthUserInfo_s($openId);
        $userInfo = $this->getOauthUserInfo($openId);
        dump($userInfo_s);
        dump($userInfo);exit;
    }

    /**
     * sendTemplate 发送模板消息
     * $first 是否第一次请求，0否1是
     * @return void
     * @author Fizz
     * @time 2018.01.05
     */
    public function sendTemplate($data = array(), $beacon_send_message_model)
    {
        try {
            if (!is_array($data)) throw new \Exception('参数错误');
            foreach ($this->message_keys as $b) {
                if (!isset($data[$b])) {
                    $this->error = $b . '参数必传';
                    return false;
                }
            }

            $result = false;
            //获取access_token
            $accessToken = $this->getAccessToken();
            $url = "https://api.weixin.qq.com/cgi-bin/message/template/send?access_token={$accessToken}";

            $miniprogram = array(
                'appid'    => config('localsystems.applet.appid'),
                'pagepath' => $data['page']??'',
            );
            $templateArray = $this->templateId($data['templateId']);//模板id
            $templateIds  = $templateArray['templateId'];//模板id
            $title         = $templateArray['title'];//模板标题

            $keyword = array();//处理发送数据
            if (!empty($data['data']) && is_array($data['data'])) {
                $count = count($data['data']);//计算数量
                foreach ($data['data'] as $k=>$v) {
                    if ($k == 0) {//第一个
                        $keyword['first'] = [
                            'value' => $v,
                            'color' => '#173177'
                        ];
                    } elseif ($k == $count -1) {//最后一个
                        $keyword['remark'] = [
                            'value' => $v,
                            'color' => '#173177'
                        ];
                    } else {//中间部分
                        $keyword['keyword'.($k)] = array(
                            "value" => $v,
                            'color' => '#173177'
                        );
                    }
                }
            }

            $post_data = array(
                'touser'      => $data['touser'],
                'template_id' => $templateIds,
                'data'        => $keyword,
            );

            if (!empty($data['url'])) {//跳去h5
                $post_data['url'] = $data['url'];
            } elseif (!empty($miniprogram['pagepath'])) {//跳去小程序
                $post_data['miniprogram'] = $miniprogram;
            }

            //发送数据
            $post_data = json_encode($post_data);
            $res = curl($url,$post_data);

            if ($res) {
                $ress = json_decode($res, true);
                if(!empty($ress) && isset($ress['errcode']) && $ress['errcode'] == 0){
                    $result = true;
                }
            }

            //记录日志
            $logData = array(
                'touser'      => $data['touser'],
                'template_id' => $templateIds,
                'title'       => $title,
                'data'        => $post_data,
                'json_data'   => $res,
                'from'        => 3,
                'to'          => isset($data['url'])?2:(isset($miniprogram['pagepath'])?1:0),//跳转到哪里，1小程序灯塔，2.h5灯塔
                'result'      => $result,
                'is'          => $data['is'],//后面（精选或者小程序）是否还可以发送
                'source'      => $data['source']//触发发送的端

            );
            WeiXinApi::getInstance()->recordSendTemplateLog($logData, $beacon_send_message_model);
        } catch(\Exception $e) {
            $e->getTraceAsString();
        } finally {
            return $result;
        }
    }

    /**
     * _create_qrcode 生成带参数的二维码
     * @return void
     * @author Fizz
     * @time 2018.03.12
     */
    public function _create_qrcode ()
    {
        error_log(date("Y-m-d H:i:s"). "---START_create_qrcode---\r\n",3, storage_path()."/logs/custom.log");

        //获取access_token
        $accessToken = $this->getAccessToken();

        $url = "https://api.weixin.qq.com/cgi-bin/qrcode/create?access_token={$accessToken}";
        error_log(date("Y-m-d H:i:s"). "---url---".$url.PHP_EOL,3, storage_path()."/logs/custom.log");

        $post_data = array(
//            'expire_seconds'  => 2592000,
            'action_name' => 'QR_LIMIT_SCENE',
            'action_info' => array(
                'scene' => array(
                    'scene_id' => 111
                )
            )
        );
        $post_data = json_encode($post_data);
//        $post_data = '{"touser":"oFjii1JMVRBudKPT2iTz174U3hF0","msgtype":"text","text":{"content":"'.$text.'"}}';
        error_log(date("Y-m-d H:i:s"). "---post_data---".$post_data.PHP_EOL,3, storage_path()."/logs/custom.log");

        $res = curl($url,$post_data);
        error_log(date("Y-m-d H:i:s"). "---res---".$res.PHP_EOL,3, storage_path()."/logs/custom.log");
        $result = json_decode($res,true);
        return 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket='.$result['ticket'];
    }

    /**
     * _upload_media 上传临时素材
     * $image_url 图片路径（绝对路径）
     * @return void
     * @author Fizz
     * @time 2018.03.12
     */
    public function _upload_media($image_url)
    {
        $accessToken = $this->getAccessToken();
        if (empty($accessToken)) {
            $accessToken = $this->getAccessToken(1);
        }
        $URL ='http://file.api.weixin.qq.com/cgi-bin/media/upload?access_token='.$accessToken.'&type=image';

        error_log(date("Y-m-d H:i:s"). "---URL---".$URL.PHP_EOL,3, storage_path()."/logs/upload_media.log");

//        $urls = public_path().'/Uploads/static/beacon/bundling1.png';
        error_log(date("Y-m-d H:i:s"). "---urls---".$image_url.PHP_EOL,3, storage_path()."/logs/upload_media.log");

        if (class_exists('\CURLFile')) {
            $data['media'] = new \CURLFile(realpath($image_url));
        } else {
            $data['media'] = '@'.realpath($image_url);
        }
        error_log(date("Y-m-d H:i:s"). "---media---".json_encode($data).PHP_EOL,3, storage_path()."/logs/upload_media.log");

//        $data = array('media'=>'@'.$urls);
        $result = curl($URL,$data);
        $data = json_decode($result,true);

        error_log(date("Y-m-d H:i:s"). "---data---".json_encode($data).PHP_EOL,3, storage_path()."/logs/upload_media.log");

        return $data['media_id']??'';
    }

    /**
     * sendMessageType 发送消息
     * @param $postObj
     * @return void
     * @author Fizz
     * @time 2018.03.27
     */
    public function sendMessageTypes (array $data)
    {
        $map = array(
            'appid' => $this->_appId,
            'scene' => 2//场景值，1扫带参二维码，2普通关注 ，3关键字回复
        );
        if (!empty($data['EventKey'])) {
            $map['scene']    = $data['scene']??1;
            $map['eventKey'] = $data['EventKey'];
        }
        $wechatMediaIdInfo = WechatSendMessage::where($map)->orderBy('sort')->get();
        error_log(date("Y-m-d H:i:s"). "---wechatMediaIdInfo---".$wechatMediaIdInfo.PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");

        $modelData = [];
        foreach ($wechatMediaIdInfo as $a) {
            $modelData[] = $a;
            if ($a->msgtype == 'image') {
                $data['isImage'] = 1;//有图片(用于后面先生成图片后才开始推送消息)
                $data['communityId'] = $data['EventKey'];//社区id
            }
        }
        if (!empty($modelData)) {
            $this->_send_custom_message($data, $modelData);
        }
    }

    /**
     * createPoster 生成海报保存在本地
     * @param $path
     * @param $imgPath
     * @return void
     * @author Fizz
     * @time 2018.06.01
     */
    private function createPoster($imageUrl, $imgPath)
    {
        // 先生成一个临时文件
        $images_content = curl($imageUrl,null,'get');
        $filename  =  storage_path().'/temporary/'.md5(time());
        file_put_contents($filename,$images_content);

        $base64  = imageEncodeBase64($filename);

        $matches = explode('base64,', $base64);
        $base64 = $matches['1'];
        $file_type = explode(':', $matches[0]);

        $type = str_replace(";", "", $file_type['1']);

        $n = explode('/', $type);
        $suff = $n['1'];

        $imgtype = '.' . strtolower($suff);

        if (!$imgtype) throw new Exception();

        $base64 = base64_decode($base64);

        file_put_contents($imgPath, $base64);
        unlink($filename);
    }

    /**
     * getMedia 获取媒体id
     * @param $openid
     * @param $number 对应海报（社区id）
     * @return $mediaId 媒体id
     * @author Fizz
     * @time 2018.06.01
     */
    private function getMedia($openid, $number)
    {
        error_log(date("Y-m-d H:i:s"). "---createPoster_openid---".$openid.PHP_EOL,3, storage_path()."/logs/subscribe.log");
        error_log(date("Y-m-d H:i:s"). "---createPoster_eventKey---".$number.PHP_EOL,3, storage_path()."/logs/subscribe.log");

        $wechatMediaIdModel = new WechatMediaId();

        $number = env('COMMIUNITYID');
        $wechatMediaIdInfo = $wechatMediaIdModel->where([
            ['appid', '=', $this->_appId],
            ['scene', '=', 1],
            ['material', '=', 1],
            ['msgtype', '=', 'image'],
            ['update_time', '>', strtotime('-3 day')],
            ['type', '=', 1],//1是获取lite的，2是获取精选的---20180329没有区分
            ['number', '=', $number],//对应海报（社区id）
            ['openid', '=', $openid]
        ])->first();

        if (!empty($wechatMediaIdInfo)) {
            $mediaId = $wechatMediaIdInfo->media_id;
        } else {
            $imgPath = storage_path()."/share/lite/".$number."/".$openid.".png";

            if(!file_exists($imgPath))
            {
                error_log(date("Y-m-d H:i:s"). "---file_exists---99999".PHP_EOL,3, storage_path()."/logs/input.log");
                $this->autoRegiseter($openid);
                $getImgArray = array(
                                    'openid'      => $openid,
                                    'communityId' => $number,
                                    'sign'   => 1//1是获取lite的，2是获取精选的
                                );
                $path = BeaconService::getInstance()->getGongImage($getImgArray);
                $this->createPoster($path, $imgPath);
            }

            if(!file_exists($imgPath))
            {
                $mediaId = '';
            } else {
                $mediaId = $this->_upload_media($imgPath);
                if (!empty($mediaId)) {//重新保存
                    $issetWechatMediaId = $wechatMediaIdModel->where([
                        ['appid', '=', $this->_appId],
                        ['scene', '=', 1],
                        ['material', '=', 1],
                        ['msgtype', '=', 'image'],
                        ['type', '=', 1],
                        ['number', '=', $number],
                        ['openid', '=', $openid]
                    ])->first();
                    if (!empty($issetWechatMediaId)) {
                        $issetWechatMediaId->media_id = $mediaId;
                        $issetWechatMediaId->saveOrFail();
                    } else {
                        $wechatMediaIdModel->appid    = $this->_appId;
                        $wechatMediaIdModel->scene    = 1;
                        $wechatMediaIdModel->material = 1;
                        $wechatMediaIdModel->msgtype  = 'image';
                        $wechatMediaIdModel->openid   = $openid;
                        $wechatMediaIdModel->media_id = $mediaId;
                        $wechatMediaIdModel->type     = 1;
                        $wechatMediaIdModel->number   = $number;
                        $wechatMediaIdModel->saveOrFail();
                    }

                }
            }
        }
        return $mediaId;
    }

    /**
     * _send_custom_message 发送客服消息
     * @return void
     * @author Fizz
     * @time 2018.03。11
     */
    public function _send_custom_message($data, $messageModel)
    {
        error_log(date("Y-m-d H:i:s"). "---START_data---".json_encode($data).PHP_EOL,3, storage_path()."/logs/subscribe.log");
        error_log(date("Y-m-d H:i:s"). "---START_messageModel---".json_encode($messageModel).PHP_EOL,3, storage_path()."/logs/subscribe.log");

        ignore_user_abort(TRUE);
        $isImage   = $data['isImage']??0;
        $openid    = $data['openid'];
        $community = $data['communityId']??0;
        if ($isImage) $mediaId = $this->getMedia($openid, $community);//生成海报并且返回媒体id
        if ($isImage && empty($mediaId)) return;

        foreach ($messageModel as $model) {
            $msgtype = $model->msgtype;
            switch($msgtype){
                case 'text';//文本
                    $contents = $model->content;
                    $post_data = '{"touser":"'.$openid.'","msgtype":"text","text":{"content":"'.$contents.'"}}';
                    break;
                case 'image';//图片
                    $contents  = $mediaId;
                    $post_data = '{"touser":"'.$openid.'","msgtype":"image","image":{"media_id":"'.$mediaId.'"}}';
                    break;
                case 'voice';//语音

                    break;
                case 'video';//视频

                    break;
                case 'music';//music

                    break;
                case 'news';//图文

                    break;
            }
            error_log(date("Y-m-d H:i:s"). "---post_data---".$post_data.PHP_EOL,3, storage_path()."/logs/subscribe.log");

            $sendData = array(
                'msgtype' => $msgtype,
                'openid'  => $openid,
                'scene'   => $model->scene,//发送场景；1关注和识别带参数二维码,2回复关键字,3自定义菜单回复
                'contents'   => $contents,
                'postData'=> $post_data
            );
            $this->recordSendLog($sendData);
        }
    }

	public function sendMessageType($data)
    {
        error_log(date("Y-m-d H:i:s"). "---STARTrrrrrrrrrrr---".json_encode($data).PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");
        dispatch(new SendCustomMessageSelected($data));
        error_log(date("Y-m-d H:i:s"). "---START---".json_encode($data).PHP_EOL,3, storage_path()."/logs/selected_subscribe.log");
    }

    //注册用户（队列）
    public function autoRegiseter (string $openid)
    {
        error_log(date("Y-m-d H:i:s"). "---START---".PHP_EOL,3, storage_path()."/logs/autoRegiseterSelected.log");
        dispatch(new subscribeAutoRegister($openid));
        error_log(date("Y-m-d H:i:s"). "---START---".$openid.PHP_EOL,3, storage_path()."/logs/autoRegiseterSelected.log");

    }

    /**
     * templateId 模板id
     * @param $id 对应一下数组模板的key
     * @return mixed
     * @author Fizz
     * @time 2018.05.14
     */
    private function templateId ($id) : Array
    {
        //模板id
        if ($this->env) {//正式环境
            $templateIds = array(
                'LdQQW_dvCZ0HCLYVyosyo9KfmM4VCTt4U0XoBMLt7L8',//0加入免费塔成功通知
                'LdQQW_dvCZ0HCLYVyosyo9KfmM4VCTt4U0XoBMLt7L8',//1免费集Call入社成功通知（已加入）
                '6zmQQmc4q3SQOc4I6sYWm_cGANRF5CAlboecdXZ0p4g',//2免费集Call入社失败通知（已加入）
                '5Lni-M9hdWH1AUBLrPROb4EVp2mlKP8DLjuFxpRXDEM',//3付费入社成功通知（已加入）
                'zIWZtaEgvYUOAdyG6VER_W3rhaWCFC0T94AjG0hhWq4',//4免费提问/付费提问/追问/已过期付费提问已被回答通知
                'vQMdhwDvB526LkIzs962Pvrs2bIw0l6BJxKl1fZVnmY',//5别人同意/拒绝我的交换微信申请
                'B-7EqvPExUAiSJTaFH5ovkCE8Ax6ZhaSGtnzVSSXHj0',//6分销佣金奖励
                'pGKmOopIFUTItj67lRidWf8MmRlDyxrh9cC4y6isvJo',//7被交换微信通知
                'QakzJ5fIrpzW2EA57XhZ4xNAbK58Kirwsm_4Jbm6Yso',//8付费提问过期未回答通知
                'tBG4l669taFHO_HiqV671ZXGJKatuMYLr8vgs-axdRI',//9集call未完成
                'NNxLFfzxIGs-7ZVu0U37eiY0eTtY4iCdVyjYjN6lJpo',//10开塔通知
                '4FdFWKOm8ngPfjT5zxptSqudrOh_5Y5qrESJ86HmxcI',//11内容更新通知
                'rwpcSmmXQSPF7IORwTR_qKXzOpJpSZ2Q_yqFuj7KoS0',//12灯塔上新
                '',//13优惠券即将过期通知
                'KuPGl9xIaEzVblbp8pq6mHmUxpiL_LDTdtm1p1N7FyM',//14帖子被回复（管理员和普通用户）/（塔主和嘉宾）
                'KuPGl9xIaEzVblbp8pq6mHmUxpiL_LDTdtm1p1N7FyM',//15评论被回复
                'KuPGl9xIaEzVblbp8pq6mHmUxpiL_LDTdtm1p1N7FyM',//16内容被点赞
                'QakzJ5fIrpzW2EA57XhZ4xNAbK58Kirwsm_4Jbm6Yso',//17问题超时未回复
                'QE6Zz-9AMJ0-6RkJOHU2s2TTenQLaDHB9xdvN1fE1Uc',//18已下单且1小时内未产生成功订单
            );
        } else {//测试环境
            $templateIds = array(
                '3BhHd5xa7QRo5bWRjVW3C0SLwDI4n-r6hBWpcwReYGE',//0加入免费塔成功通知
                '3BhHd5xa7QRo5bWRjVW3C0SLwDI4n-r6hBWpcwReYGE',//1免费集Call入社成功通知（已加入）
                'qjH9avIpHJxiy4rx2FAMKm8O76rTfOOCEwDIf5-3LZY',//2免费集Call入社失败通知（已加入）
                'qUwOe4faFubwM15lslsFZ1CHUIwU86YMsyt9-Emk3V0',//3付费入社成功通知（已加入）
                'LsPJo9KBX6G1WuUzqS_h6v_UqG0qQOO5IFBQemEZvwo',//4免费提问/付费提问/追问/已过期付费提问已被回答通知
                '44c0WOkHuQ9JAReoBFU7iVOgemhO30CAazznV-tDBuE',//5别人同意/拒绝我的交换微信申请
                'l-U1Lgm-7Jc3kahI0D3qr5KLnYqwWAF5efGUONmssGw',//6分销佣金奖励
                'OjCGp0ozC8YGFgBNrEh7ZKh6AEdB5wGKwVf7fBEKNuE',//7被交换微信通知
                'THkZHzZnfKw89GTE5fzLdodj8Q1Mh4mEfr7ysgXyLmc',//8付费提问过期未回答通知
                'op9NaEuqjvwgF_XhwVLzhFVubSQu8Q8FEo6xJ0O1KjQ',//9集call未完成
                'jJiJA2SzF5kMMNrWmc8s-A1Sql_wm0moEqTaD0rpOh4',//10开塔通知
                'ueyY1rEbsrYmOjrwhiBWdnovdU1vOkknfCRPZn4MU9U',//11内容更新通知
                'RCyk81Ap2jmj5QijXyIWTs7T-F09IT2ZlL_MQSJWgSk',//12灯塔上新
                '',//13优惠券即将过期通知
                'gNfoJtrBrpz9r_klopeGo2ct6azlF5J1gbDl6e2sxnE',//14帖子被回复（管理员和普通用户）/（塔主和嘉宾）
                'gNfoJtrBrpz9r_klopeGo2ct6azlF5J1gbDl6e2sxnE',//15评论被回复
                'gNfoJtrBrpz9r_klopeGo2ct6azlF5J1gbDl6e2sxnE',//16内容被点赞
                'THkZHzZnfKw89GTE5fzLdodj8Q1Mh4mEfr7ysgXyLmc',//17问题超时未回复
                '85OZb7iG_dykOGSgPGLGPskphz1wOKyV8ht69GNwdhM',//18已下单且1小时内未产生成功订单
            );
        }

        //模板标题
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
}
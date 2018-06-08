<?php
//极光推送类
class JPush{

    private $app_key       = '1bc37ddab6bbd103badcf792';
    private $master_secret = '5d73076c8131d3da19a03be6';
    private $url           = "https://api.jpush.cn/v3/push";//接口地址

    public function __construct($app_key = null, $master_secret = null, $url = null) {
        $app_key       && $this->app_key = $app_key;
        $master_secret && $this->master_secret = $master_secret;
        $url           && $this->url = $url;
    }

    //$receiver 接收者的信息:
    //    all 字符串 该产品下面的所有用户
    //    tag(20个) Array标签组(并集): tag=>array('昆明','北京','曲靖','上海');
    //    tag_and(20个) Array标签组(交集): tag_and=>array('广州','女');

    //$content 推送的内容。
    //$m_type  推送附加字段的类型(可不填) http,tips,chat....
    //$m_txt   推送附加字段的类型对应的内容(可不填) 可能是url,可能是一段文字。
    //$m_time  保存离线时间的秒数默认为一天(可不传)单位为秒
    public function push($receiver=array(),$title='',$content='',$extras=array(),$m_time='86400'){
    	$base64 = base64_encode($this->app_key.':'.$this->master_secret);
        $header = array("Authorization:Basic $base64","Content-Type:application/json");
        $data   = array();

        $data['platform'] = 'all';          //目标用户终端手机的平台类型android,ios,winphone
        $data['audience'] = $receiver;      //目标用户

        $data['notification'] = array(
            //统一的模式--标准模式
            "alert" => "你好！",
            //安卓自定义
            "android" => array(
                "alert"      => $content,
               // "title"      => "你好！",
                "builder_id" => 1,
                "extras"     => $extras
            ),
            //ios的自定义
            "ios"=>array(
                "alert"  => $content,
               // "title"  => "你好！",
                "badge"  => "1",
                "sound"  => "default",
                "extras" => $extras
            ),
        );

        //苹果自定义
        /*$data['message'] = array(
            "msg_content" => $content,
            "extras"      => $extras
        );*/

        //附加选项
        $data['options'] = array(
            "sendno"          => time(),
            "time_to_live"    => $m_time,   //保存离线时间的秒数默认为一天
            "apns_production" => 0,        //指定 APNS 通知发送环境：0开发环境，1生产环境。
        );

        $param = json_encode($data);
        $res   = $this->push_curl($param,$header);

        if($res){
            return $res;
        }else{
            return false;
        }
    }

    //推送的Curl方法
    public function push_curl($param = "",$header = "") {

        if (empty($param)) return false;

        $postUrl  = $this->url;
        $curlPost = $param;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$postUrl);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $curlPost);
        curl_setopt($ch, CURLOPT_HTTPHEADER,$header);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        $data = curl_exec($ch);
        curl_close($ch);

        return $data;
    }
}
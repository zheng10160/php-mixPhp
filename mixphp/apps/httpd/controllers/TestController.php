<?php
/**
 * Created by PhpStorm.
 * User: localuser1
 * Date: 2019/2/21
 * Time: 上午11:01
 */

namespace apps\httpd\controllers;

use mix\facades\Redis;
use mix\http\Controller;
use mix\facades\Request;
class TestController extends Controller
{
    public function actionIndex()
    {
        Request::getRawBody();
        $dacq = json_decode(file_get_contents('php://input'));
        //$dacq = [];
        //尝试get-params中获取
        if(empty($dacq)){
            $dacq = (object) array('sign'=>'','app_id'=>'','ts'=>'','act'=>'','data'=>'');
            $dacq->sign = Request::get('sign') ? Request::get('sign') : '';
            $dacq->app_id = Request::get('app_id') ? Request::get('app_id') : '';
            $dacq->ts = Request::get('ts') ? Request::get('ts') : '';
            $dacq->act = Request::get('act') ? Request::get('act') : '';
            $dacq->data = Request::get('data') ? Request::get('data') : '';
        }

        if(empty($dacq) || !is_object($dacq)){
            return $this->en_json(90003, '来源或操作错误！');
        }

        GLOBAL $post_config;
        $list_key = 'api:post_data:dacq'; //队列key

//拉取配置参数
        $key_config = 'api:post_data:' . $dacq->app_id . ':' . $dacq->act;
        $ret = Redis::get($key_config);

        if(empty($ret)){
            return $this->en_json(90003, '来源或操作错误！');
        }else{
            $post_config = json_decode($ret, true, 512);
        }

//签名验证
        $this->valid($dacq);

//验证重复
        $data_str = $dacq->data. ',,' . $dacq->act . ',,'. $dacq->app_id;
        $uniq = md5($data_str);
        if(0 && !empty(Redis::get($uniq))){
            return  $this->en_json('90004','提交数据重复！');
        }

//数据存入redis(用于检验唯一性)
        Redis::set('api:uniq_data:md5key:' . $uniq, 1, $post_config['uniq_ts']);


//解析data
        $data_arr = explode("\n", base64_decode($dacq->data));
//数据存入redisList
        $total = 0; //存入数据条数
        $well_push_arr = [//将要存入的数据
            'data_table' => $post_config['post_table'],
            'data_json' =>[
                'app_id' => $dacq->app_id,
                'act' => $dacq->act,
                'ip' => '127.0.0.1',
                'data' => '',
                'timestamp' => $dacq->ts,
                'origin' => json_encode($dacq), //原始数据放入origin
                'created_at' => date('Y-m-d H:i:s'),
            ]
        ];
        foreach ($data_arr as $data) {
            if(empty($data)){
                continue;
            }
            $well_push_arr['data_json']['data'] = $data;
            if(Redis::lpush($list_key, json_encode($well_push_arr)) > 0){
                $total++;
            }
        }

        return $this->en_json(200, 'ok', $total);

    }

    /**
     * 签名验证
     * @param $dacp
     * @throws \Exception
     */
    function valid($dacq)
    {
        GLOBAL $post_config;
        //时间差验证,服务器时间差超过限定返回错误
        $time = time();
        if($dacq->ts > $time || $time - $dacq->ts > $post_config['uniq_ts']){
            return  $this->en_json(90002, '超过时间限制！');
        }
        $arr = [(string)$dacq->ts, (string)$dacq->app_id, (string)$post_config['secret']];
        sort($arr);
        $str = implode('', $arr);
        $sign = md5($str);
        if($sign !== $dacq->sign){
            return $this->en_json(90001, '签名错误！');
        }
    }

    function en_json($code = 0, $error = 'ok', $push_num = 0)
    {
        if($push_num > 0){

            return json_encode(['code' => $code, 'error' => $error, 'push_num' => $push_num]);
        }else{
            return json_encode(['code' => $code, 'error' => $error]);
        }

        die;
    }
}
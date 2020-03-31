<?php
/**
 * Created by PhpStorm.
 * User: hiliq
 * Date: 2019/2/26
 * Time: 13:27
 */

namespace app\ucenter\controller;

use app\model\Comments;
use app\model\Message;
use app\model\RedisHelper;
use app\model\User;
use app\model\UserFinance;
use app\service\FinanceService;
use app\service\PromotionService;
use app\service\UserService;
use think\facade\Cache;
use think\facade\Validate;
use util\Common;

class Users extends BaseUcenter
{
    protected $userService;
    protected $financeService;
    protected $promotionService;

    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->userService = app('userService');
        $this->financeService = app('financeService');
        $this->promotionService = app('promotionService');
    }

    public function bookshelf()
    {
        $favors = $this->userService->getFavors($this->uid);
        $this->assign([
            'favors' => $favors,
            'header_title' => '我的收藏'
        ]);
        return view($this->tpl);
    }

    public function delfavors()
    {
        if ($this->request->isPost()) {
            $ids = explode(',', input('ids')); //书籍id;
            $this->userService->delFavors($this->uid, $ids);
            return ['err' => 0, 'msg' => '删除收藏'];
        } else {
            return ['err' => 1, 'msg' => '非法请求'];
        }
    }
    
    public function history()
    {
        $redis = RedisHelper::GetInstance();
        $vals = $redis->hVals($this->redis_prefix . ':history:' . $this->uid);
        $books = array();
        foreach ($vals as $val) {
            $books[] = json_decode($val, true);
        }
        $this->assign([
            'books' => $books,
            'header_title' => '阅读历史'
        ]);
        return view($this->tpl);
    }

    public function ucenter()
    {
        $balance = cache('balance:' . $this->uid); //当前用户余额
        if (!$balance) {
            $balance = $this->financeService->getBalance($this->uid);
            cache('balance:' . $this->uid, $balance, '', 'pay');
        }
        $user = User::get($this->uid);
        $time = $user->vip_expire_time - time();
        $day = 0;
        if ($time > 0) {
            $day = ceil(($user->vip_expire_time - time()) / (60 * 60 * 24));
        }
        session('xwx_vip_expire_time', $user->vip_expire_time); //在session里更新用户vip过期时间
        $this->assign([
            'balance' => $balance,
            'user' => $user,
            'header_title' => '个人中心',
            'day' => $day
        ]);
        return view($this->tpl);
    }

    public function userinfo()
    {
        $this->assign([
            'header_title' => '我的资料'
        ]);
        return view($this->tpl);
    }

    public function update()
    {
        if ($this->request->isPost()) {
            $nick_name = input('nickname');
            $user = new User();
            $user->nick_name = $nick_name;
            $result = $user->isUpdate(true)->save(['id' => $this->uid]);
            if ($result) {
                session('xwx_nick_name', $nick_name);
                return ['msg' => '修改成功'];
            } else {
                return ['msg' => '修改失败'];
            }
        }
        return ['msg' => '非法请求'];
    }

    public function bindphone()
    {
        $user = User::get($this->uid);
        $redis = RedisHelper::GetInstance();
        if ($this->request->isPost()) {
            $code = trim(input('txt_phonecode'));
            $phone = trim(input('txt_phone'));
            if (verifycode($code, $phone) == 0) {
                return ['err' => 1, 'msg' => '验证码错误'];
            }
            if (User::where('mobile', '=', $phone)->find()) {
                return ['err' => 1, 'msg' => '该手机号码已经存在'];
            }
            $user->mobile = $phone;
            if ($user->vip_expire_time < time()) { //说明vip已经过期
                $user->vip_expire_time = time() + 1 * 30 * 24 * 60 * 60;
            } else { //vip没过期，则在现有vip时间上增加
                $user->vip_expire_time = $user->vip_expire_time + 1 * 30 * 24 * 60 * 60;
            }
            session('xwx_vip_expire_time', $user->vip_expire_time); //在session里更新用户vip过期时间
            $user->isUpdate(true)->save();

//            $finance = UserFinance::where([
//                ['user_id','=',$user->id],
//                ['usage','=',5]
//            ])->find(); //查询是否之前有过绑定手机的奖励
//            if (empty($finance) || is_null($finance)) {
//                $finance = new UserFinance();
//                $finance->user_id = $user->id;
//                $finance->money = (int)config('payment.mobile_bind_rewards');
//                $finance->usage = 5;
//                $finance->summary = '绑定手机奖励';
//                $finance->save();
//                cache('rewards:' . $user->id, null); //删除奖励缓存
//                cache('rewards:sum:' . $user->id, null); //删除奖励总和缓存
//                Cache::clear('pay'); //清除支付缓存
//            }
            session('xwx_user_mobile', $phone);
            return ['err' => 0, 'msg' => '绑定成功'];
        }

        //如果用户手机已经存在，并且没有进行修改手机验证，也就是没有解锁缓存
        if (!empty($user->mobile)) {
            if (!$redis->exists($this->redis_prefix . ':xwx_mobile_unlock:' . $this->uid)) {
                $this->redirect('/userphone'); //则重定向至手机信息页
            }
        }

        $this->assign([
            'header_title' => '绑定手机'
        ]);
        return view($this->tpl);
    }

    public function verifyphone()
    {
        $phone = input('txt_phone');
        $code = input('txt_phonecode');
        if (verifycode($code, $phone) == 0) {
            return ['err' => 1, 'msg' => '验证码错误'];
        }
        return ['err' => 0];
    }

    public function sendcms()
    {
        $code = generateRandomString();
        $phone = trim(input('phone'));
        $validate = Validate::make([
            'phone' => 'mobile'
        ]);
        $data = [
            'phone' => $phone
        ];
        if (!$validate->check($data)) {
            return ['msg' => '手机格式不正确'];
        }
        $sms = new \util\Common();
        $result = $sms->sendcode($this->uid, $phone, $code);
        if ($result['status'] == 0) { //如果发送成功
            session('xwx_sms_code', $code); //写入session
            session('xwx_cms_phone', $phone);
            $redis = RedisHelper::GetInstance();
            $redis->set($this->redis_prefix . ':xwx_mobile_unlock:' . $this->uid, 1, 300); //设置解锁缓存，让用户可以更改手机
        }
        return ['msg' => $result['msg']];
    }

    public function userphone()
    {
        $user = User::get($this->uid);
        $this->assign([
            'user' => $user,
            'header_title' => '管理手机'
        ]);
        return view($this->tpl);
    }

    public function resetpwd()
    {
        if ($this->request->isPost()) {
            $pwd = input('password');
            $validate = new \think\Validate;
            $validate->rule('password', 'require|min:6|max:21');

            $data = [
                'password' => $pwd,
            ];
            if (!$validate->check($data)) {
                return ['msg' => '密码在6到21位之间', 'err' => 1];
            }
            $user = User::get($this->uid);
            $user->password = $pwd;
            $user->isUpdate(true)->save();
            return ['msg' => '修改成功', 'err' => 0];
        }
        $this->assign([
            'header_title' => '修改密码'
        ]);
        return view($this->tpl);
    }

    public function commentadd()
    {
        $book_id = input('book_id');
        $redis = RedisHelper::GetInstance();
        if ($redis->exists('comment_lock:' . $this->uid)) {
            return json(['msg' => '每10秒只能评论一次', 'err' => 1]);
        } else {
            $comment = new Comments();
            $comment->user_id = $this->uid;
            $comment->book_id = $book_id;
            $comment->content = strip_tags(input('comment'));
            $result = $comment->save();
            if ($result) {
                $redis->set('comment_lock:' . $this->uid, 1, 10); //加10秒锁
//                $dir = App::getRootPath() . 'public/static/upload/comments/' . $book_id;
//                if (!file_exists($dir)) {
//                    mkdir($dir, 0777, true);
//                }
//                file_put_contents($dir . '/' . $comment->id . '.txt', $content);
                cache('comments:' . $book_id, null);
                return json(['msg' => '评论成功', 'err' => 0]);
            } else {
                return json(['msg' => '评论失败', 'err' => 1]);
            }
        }
    }

    public function leavemsg()
    {
        if ($this->request->isPost()) {
            $msg = new Message();
            $msg->type = 0;//类型为用户留言
            $msg->msg_key = $this->uid; //这里的key为留言用户的id
            $msg->content = strip_tags(input('content'));//过滤掉用户输入的HTML标签
            $res = $msg->save();
            if ($res) {
//                //保存用户留言的文件路径
//                $dir = Env::get('root_path') . '/public/static/upload/message/' . $msg->id . '/';
//                if (!file_exists($dir)) {
//                    mkdir($dir, 0777);
//                }
//                $savename = $dir . 'msg.txt';
//                file_put_contents($savename, $content);
                return ['err' => 0, 'msg' => '留言成功'];
            } else {
                return ['err' => 1, 'msg' => '留言失败'];
            }
        }
        $this->assign('header_title', '留言反馈');
        return view($this->tpl);
    }

    public function message()
    {
        $map[] = ['msg_key', '=', $this->uid];
        $map[] = ['type', '=', 0]; //类型为用户留言
        $type = 'util\Page';
        $num = 10;
        if ($this->request->isMobile()) {
            $type = 'util\MPage';
            $num = 1;
        }
        $msgs = Message::where($map)->paginate($num, true,
            [
                'type' => $type,
                'var_page' => 'page',
            ])->each(function ($item, $key) {
//            $dir = Env::get('root_path') . '/public/static/upload/message/' . $item['id'] . '/';
//            $item['content'] = file_get_contents($dir . 'msg.txt'); //获取用户留言内容

            //利用本条留言的ID查出本条留言的所有回复留言
            $map2[] = ['msg_key', '=', $item['id']];
            $map2[] = ['type', '=', 1]; //类型为回复
            $replys = Message::where($map2)->select();
            $item['replys'] = $replys;
//            foreach ($replys as &$reply) {
//                $reply['content'] = file_get_contents($dir . $reply->id . '.txt');
//            }
        });

        $this->assign([
            'msgs' => $msgs,
            'header_title' => '查看回复'
        ]);
        return view($this->tpl);
    }

    public function promotion()
    {
        $rewards = cache('rewards:' . $this->uid);
        if (!$rewards) {
            $rewards = $this->promotionService->getRewardsHistory();
        }

        $sum = cache('rewards:sum:' . $this->uid);
        if (!$sum) {
            $sum = $this->promotionService->getRewardsSum();
        }

        $url = config('site.url');
        $util = new Common();
        $shortUrl = $url.'?pid='.session('xwx_user_id');
        //$shortUrl =  $util->shorturl($url.'?pid='.session('xwx_user_id'));
        $this->assign([
            'rewards' => $rewards,
            'promotion_rate' => (float)config('payment.promotional_rewards_rate') * 100,
            'reg_reward' => config('payment.reg_rewards'),
            'promotion_sum' => $sum,
            'header_title' => '推广赚币',
            'shortUrl' => $shortUrl
        ]);
        return view($this->tpl);
    }
}
<?php


namespace app\index\controller;

use app\model\User;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\facade\View;

class Users extends BaseUc
{
    protected $userModel;
    protected $financeModel;

    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->userModel = app('userModel');
        $this->financeModel = app('financeModel');
    }

    public function ucenter()
    {
        $balance = $this->financeModel->getBalance($this->uid);
        try {
            $user = User::findOrFail($this->uid);
            $time = $user->vip_expire_time - time();
            $day = 0;
            if ($time > 0) {
                $day = ceil(($user->vip_expire_time - time()) / (60 * 60 * 24));
            }
            session('vip_expire_time', $user->vip_expire_time); //在session里更新用户vip过期时间
            View::assign([
                'balance' => $balance,
                'user' => $user,
                'day' => $day
            ]);
            return view($this->tpl);
        } catch (DataNotFoundException $e) {
            abort(404, $e->getMessage());
        } catch (ModelNotFoundException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function bookshelf()
    {
        return view($this->tpl);
    }

    public function delfavors()
    {
        if (request()->isPost()) {
            $ids = explode(',', input('ids')); //书籍id;
            $this->userModel->delFavors($this->uid, $ids);
            return json(['err' => 0, 'msg' => '删除收藏']);
        } else {
            return json(['err' => 1, 'msg' => '非法请求']);
        }
    }

    public function history()
    {      
        return view($this->tpl);
    }

    public function userinfo()
    {
        if (request()->isPost()) {
            $nick_name = input('nickname');
            try {
                $user = User::findOrFail($this->uid);
                $user->nick_name = $nick_name;
                $result = $user->save();
                if ($result) {
                    session('xwx_nick_name', $nick_name);
                    return json(['msg' => '修改成功']);
                } else {
                    return json(['msg' => '修改失败']);
                }
            } catch (DataNotFoundException $e) {
            } catch (ModelNotFoundException $e) {
                return json(['msg' => '用户不存在']);
            }
        }
        return view($this->tpl);
    }

    public function bindphone()
    {
        try {
            $user = User::findOrFail($this->uid);
            if ($this->request->isPost()) {
                $code = trim(input('txt_phonecode'));
                $phone = trim(input('txt_phone'));
                if (verifycode($code, $phone) == 0) {
                    return json(['err' => 1, 'msg' => '验证码错误']);
                }
                if (User::where('mobile', '=', $phone)->find()) {
                    return json(['err' => 1, 'msg' => '该手机号码已经存在']);
                }
                $user->mobile = $phone;
                if ($user->vip_expire_time < time()) { //说明vip已经过期
                    $user->vip_expire_time = time() + 1 * 30 * 24 * 60 * 60;
                } else { //vip没过期，则在现有vip时间上增加
                    $user->vip_expire_time = $user->vip_expire_time + 1 * 30 * 24 * 60 * 60;
                }
                session('vip_expire_time', $user->vip_expire_time); //在session里更新用户vip过期时间
                $user->save();

                session('xwx_user_mobile', $phone);
                return json(['err' => 0, 'msg' => '绑定成功']);
            }
        } catch (ModelNotFoundException $e) {
            return json(['err' => 1, 'msg' => '该用户不存在']);
        }

        //如果用户手机已经存在，并且没有进行修改手机验证，也就是没有解锁缓存
        if (!empty($user->mobile)) {
            if (empty(cookie('xwx_mobile_unlock:' . $this->uid))) { //没有解锁缓存
                $this->redirect('/userphone'); //则重定向至手机信息页
            }
        }

        return view($this->tpl);
    }

    public function verifyphone()
    {
        $phone = input('txt_phone');
        $code = input('txt_phonecode');
        if (verifycode($code, $phone) == 0) {
            return json(['err' => 1, 'msg' => '验证码错误']);
        }
        return json(['err' => 0]);
    }

    public function sendcms()
    {
        $code = generateRandomString();
        $phone = trim(input('phone'));
//        $validate = Validate::make([
//            'phone' => 'mobile'
//        ]);
//        $data = [
//            'phone' => $phone
//        ];
//        if (!$validate->check($data)) {
//            return ['msg' => '手机格式不正确'];
//        }
        $result = sendcode($phone, $code);
        if ($result['status'] == 0) { //如果发送成功
            session('xwx_sms_code', $code); //写入session
            session('xwx_cms_phone', $phone);
            cookie('xwx_mobile_unlock:' . $this->uid, 1, 300); //设置解锁缓存，让用户可以更改手机
        }
        return json(['msg' => $result['msg']]);
    }

    public function userphone()
    {
        try {
            $user = User::findOrFail($this->uid);
            View::assign([
                'user' => $user,
            ]);
            return view($this->tpl);
        } catch (ModelNotFoundException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function promotion()
    {
        $shortUrl = config('site.domain').'?pid='.session('xwx_user_id');
        View::assign([
            'promotion_rate' => (float)config('payment.promotional_rewards_rate') * 100,
            'reg_reward' => config('payment.reg_rewards'),
            'shortUrl' => $shortUrl
        ]);
        return view($this->tpl);
    }

    public function resetpwd()
    {
        if (request()->isPost()) {
            $pwd = input('password');
            $validate = new \think\Validate;
            $validate->rule('password', 'require|min:6|max:21');

            $data = [
                'password' => $pwd,
            ];
            if (!$validate->check($data)) {
                return json(['msg' => '密码在6到21位之间', 'success' => 0]);
            }
            try {
                $user = User::findOrFail($this->uid);
                $user->password = $pwd;
                $user->save();
                return json(['msg' => '修改成功', 'success' => 1]);
            } catch (DataNotFoundException $e) {
                return json(['success' => 0, 'msg' => '用户不存在']);
            } catch (ModelNotFoundException $e) {
                return json(['success' => 0, 'msg' => '用户不存在']);
            }
        }
        return \view($this->tpl);
    }
}
<?php


namespace app\app\controller;


use app\common\RedisHelper;
use app\model\Book;
use app\model\Chapter;
use app\model\User;
use app\model\UserBuy;
use app\model\UserFinance;
use app\model\UserOrder;
use app\model\VipCode;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\facade\Db;

class Finance extends BaseAuth
{
    protected $financeService;
    protected $pay;
    protected $promotionService;

    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->financeService = app('financeService');
        $this->pay = app('payService');
        $this->promotionService = app('promotionService');
    }

    public function getBalance()
    {
        $balance = cache('balance:' . $this->uid); //当前用户余额
        if (!$balance) {
            $balance = $this->financeService->getBalance($this->uid);
            cache('balance:' . $this->uid, $balance, '', 'pay');
        }

        $result = [
            'success' => 1,
            'balance' => $balance
        ];
        return json($result);
    }

    public function getCharges()
    {
        $map[] = ['user_id', '=', $this->uid];
        $map[] = ['usage', '=', 1];
        $charges = UserFinance::where($map)->order('id', 'desc')->limit(10)->select();

        return json(['success' => 1, 'charges' => $charges]);
    }

    public function getSpendings()
    {
        $map[] = ['user_id', '=', $this->uid];
        $spendings = UserBuy::where($map)->order('id', 'desc')->limit(10)->select();
        foreach ($spendings as &$buy) {
            $buy['chapter'] = Chapter::findOrFail($buy->chapter_id);
            $book = Book::findOrFail($buy->book_id);
            if (substr($book->cover_url, 0, 4) === "http") {

            } else {
                $book->cover_url = $this->img_domain . $book->cover_url;
            }
            $buy['book'] = $book;
        }
        return json(['success' => 1, 'spendings' => $spendings]);
    }

    public function buyhistory()
    {
        $buys = UserBuy::where('user_id', '=', $this->uid)->order('id', 'desc')->limit(50)->select();
        try {
            foreach ($buys as &$buy) {
                $chapter = Chapter::findOrFail($buy['chapter_id']);
                $book = Book::findOrFail($buy['book_id']);
                if ($this->end_point == 'id') {
                    $book['param'] = $book['id'];
                } else {
                    $book['param'] = $book['unique_id'];
                }
                $buy['chapter'] = $chapter;
                $buy['book'] = $book;
            }
        } catch (DataNotFoundException $e) {
            return json(['success' => 0, 'msg' => $e->getMessage()]);
        } catch (ModelNotFoundException $e) {
            return json(['success' => 0, 'msg' => $e->getMessage()]);
        }
        return json(['success' => 1, 'buys' => $buys]);
    }

    public function charge()
    {
        $data = request()->param();
        $money = $data['money'];  //用户充值金额
        $pay_type = $data['pay_type']; //是充值金币还是购买vip
        $pay_code = $data['code'];
        $order = new UserOrder();
        $order->user_id = $this->uid;
        $order->money = $money;
        $order->status = 0; //未完成订单
        $order->pay_type = $pay_type;
        $order->expire_time = time() + 86400; //订单失效时间往后推一天
        $res = $order->save();
        if ($res) {
            $number = config('site.domain').'_';
            $r = $this->pay->submit($number . $order->id, $money, $pay_type, $pay_code);
            if ($r['type'] == 'html') {
                return json(['success' => 1, 'type' => 'html', 'html' => $r['content'], 'order_id' => 'xwx_order_' . $order->id]);
            } else {
                return json(['success' => 1, 'type' => 'url', 'url' => $r['content'], 'order_id' => 'xwx_order_' . $order->id]);
            }
        } else {
            return json(['success' => 0, 'msg' => '充值失败']);
        }
    }

    public function orderQuery()
    {
        $order_id = input('order_id');
        $res = $this->pay->query($order_id);
        return $res;
    }

    public function buychapter()
    {
        $id = input('chapter_id');
        $chapter = Chapter::with('book')->cache('buychapter:' . $id, 600, 'redis')->find($id);
        $result = $this->financeService->buyChapter($chapter, $this->uid);
        return $result;
    }

    public function getPayments()
    {
        $payment = array();
        $payment['vip'] = config('payment.vip');
        $payment['money'] = config('payment.money');
        $payment['pay_code'] = config('payment.pay.channel');
        $payment['sign_rewards'] = config('payment.sign_rewards');
        $payment['login_rewards'] = config('payment.login_rewards');
        $payment['share_rewards']= config('payment.share_rewards');
        return json([
            'success' => 1,
            'payment' => $payment
        ]);
    }

    public function dailySign()
    {
        $redis = RedisHelper::GetInstance();
        $day = 'dailySign:' . $this->uid . ':' . date('d');
        $val = (int)$redis->get($day);
        if ($val == 1) {
            return json(['success' => 0, 'msg' => '今日已经签到']);
        } else {
            $amount = config('payment.sign_rewards');
            $this->promotionService->setReward($this->uid, $amount, 5, '每日签到奖励');
            $redis->set($day, 1, 60 * 60 * 24); //写入锁
            return json(['success' => 1, 'reward' => $amount]);
        }
    }

    public function vipexchange()
    {
        $str_code = trim(input('code'));
        try {
            $user = User::findOrFail($this->uid);
            $code = VipCode::where('code', '=', $str_code)->findOrFail();
            if ((int)$code->used == 3) {
                return json(['success' => 0, 'msg' => '该vip码已经被使用']);
            }

            Db::startTrans();
            Db::table($this->prefix . 'vip_code')->update([
                'used' => 3, //变更状态为使用
                'id' => $code->id,
                'update_time' => time()
            ]);

            $vip_expire_time = (int)$user->vip_expire_time;
            if ($vip_expire_time < time()) { //说明vip已经过期
                $new_expire_time = strtotime('+' . (int)$code->add_day . ' days', time());
            } else { //vip没过期，则在现有vip时间上增加
                $new_expire_time = strtotime('+' . (int)$code->add_day . ' days', $vip_expire_time);
            }

            Db::table($this->prefix . 'user')->update([
                'vip_expire_time' => $new_expire_time,
                'id' => $this->uid
            ]);
            // 提交事务
            Db::commit();
            session('vip_expire_time', $new_expire_time);

            return json(['success' => 1, 'msg' => 'vip码使用成功']);
        } catch (ModelNotFoundException $e) {
            return json(['success' => 0, 'msg' => 'vip码兑换出错']);
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            return json(['success' => 0, 'msg' => $e->getMessage()]);
        }
    }
}
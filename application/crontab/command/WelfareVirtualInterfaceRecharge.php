<?php


namespace app\crontab\command;

use app\constant\JuheConstant;
use app\constant\NanmuConstant;
use app\fmyadmin\model\User;
use app\fmyadmin\model\UserBeansLog;
use app\fmyadmin\model\WelfareGoodsOrders;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Db;
use think\facade\Log;
use app\fmyadmin\model\WelfareVirtualInterface;
use virtualInterfaceRecharge\RechargeWithCall;
use virtualInterfaceRecharge\RechargeWithFlow;
use virtualInterfaceRecharge\RechargeWithVideo;

/**
 * 虚拟商品充值
 *
 * Class WelfareVirtualInterfaceRecharge
 * @package app\crontab\command
 */
class WelfareVirtualInterfaceRecharge extends Command
{
    /**
     * 配置(描述)
     */
    protected function configure()
    {
        $this->setName('WelfareVirtualInterfaceRecharge')->setDescription("虚拟商品充值");
    }

    /**
     * 执行
     *
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \think\exception\DbException
     */
    protected function execute(Input $input, Output $output)
    {
        Log::info("虚拟商品充值开始");

        WelfareGoodsOrders::where([
            'order_type' => WelfareGoodsOrders::ORDER_TYPE_VIRTUAL, // 虚拟商品
            'order_status' => WelfareGoodsOrders::STATUS_TO_BE_SHIPPED // 已支付/待发货
        ])->with(['sku' => ['attrKey', 'attrValue']])->chunk(50, function ($order) {
            foreach ($order as $item) {
                # 虚拟类型 1、视频会员 2、流量 3、话费
                $type = $item->sku->interface->type;
                switch ($type) {
                    # 视频会员
                    case WelfareVirtualInterface::TYPE_VIDEO:
                        $this->virtualRechargeWithVideo($item);
                        break;
                    # 流量
                    case WelfareVirtualInterface::TYPE_FLOW:
                        $this->virtualRechargeWithFlow($item);
                        break;
                    # 话费
                    case WelfareVirtualInterface::TYPE_CALL_CHARGE:
                        $this->virtualRechargeWithCall($item);
                        break;
                    default:
                        Log::error("虚拟类型不存在,数据分析:" . json_encode($item, JSON_UNESCAPED_UNICODE));
                }
            }
        });

        Log::info("虚拟商品充值结束");
    }

    /**
     * 视频会员充值
     *
     * @param $order
     */
    public function virtualRechargeWithVideo($order)
    {
        $video = new RechargeWithVideo();

        # 发送南木视频会员充值请求
        $result = $video->sendNanmuRecharge($order);

        Log::info("会员充值接收数据:" . json_encode($result, JSON_UNESCAPED_UNICODE));

        # 验证是否充值成功
        if ($result['code'] == 1000 && $result['data']['code'] == NanmuConstant::NANMU_ORDER_OK) { // 充值成功

            $order->setOrderStatusComplete(); // 订单完成

            Log::info("视频会员充值成功,返回数据:" . json_encode($result, JSON_UNESCAPED_UNICODE));

        } elseif ($result['code'] == 1000 && $result['data']['code'] == NanmuConstant::NANMU_OK) { // 充值中

            $order->setOrderStatusDelivered(); // 订单已发货

            Log::info("视频会员充值中,返回数据:" . json_encode($result, JSON_UNESCAPED_UNICODE));

        } else { // 充值失败

            Db::transaction(function () use ($order, $result) {
                # 退还环保豆
                $user = User::find($order->user_id);
                $user->setInc('user_beans', $order->goods_price);
                # 记录环保豆日志
                UserBeansLog::create([
                    'admin_id' => -1, // 系统
                    'beans' => $order->goods_price,
                    'source_type' => 1,
                    'beans_type' => 1,
                    'desc' => '南木科技视频会员充值失败返还环保豆',
                    'rem_beans' => User::where('id', $order->user_id)->value('user_beans'),
                    'user_id' => $order->user_id
                ]);
                # 取消订单
                $order->setOrderStatusCancel(); // 订单取消
                # 记录日志
                Log::info("视频会员充值失败,返回数据:" . json_encode($result, JSON_UNESCAPED_UNICODE));
            });
            # 发送模板消息
        }
    }

    /**
     * 流量充值
     *
     * @param $order
     */
    public function virtualRechargeWithFlow($order)
    {
        $flow = new RechargeWithFlow();

        # 发送聚合流量api请求
        $result = $flow->sendJuheFlowRecharge($order);

        Log::info("流量充值接收数据:" . json_encode($result, JSON_UNESCAPED_UNICODE));

        # 验证是否提交成功
        if ($result['code'] == 1000 && isset($result['data']['error_code']) && $result['data']['error_code'] == JuheConstant::RECHARGE_OK) {
            # 查询流量订单
            $resultTa = $flow->sendJuheFlowOrdersTa($order);
            Log::info("查询流量订单返回数据:" . json_encode($resultTa, JSON_UNESCAPED_UNICODE));

            if ($resultTa['code'] == 1000 && isset($resultTa['data']['error_code']) && $resultTa['data']['error_code'] == JuheConstant::RECHARGE_OK && $resultTa['data']['result']['game_state'] == '0') { // 充值成功
                $order->setOrderStatusDelivered(); // 已发货
            }
        } else { // 充值失败
            # 查询流量订单
            $resultTa = $flow->sendJuheFlowOrdersTa($order);

            Log::info("查询流量订单返回数据:" . json_encode($resultTa, JSON_UNESCAPED_UNICODE));

            if ($resultTa['code'] == 1000 && isset($resultTa['data']['error_code']) && $resultTa['data']['error_code'] == JuheConstant::RECHARGE_OK && $resultTa['data']['result']['game_state'] == '9') {
                Db::transaction(function () use ($order, $resultTa) {
                    # 退还环保豆
                    $user = User::find($order->user_id);
                    $user->setInc('user_beans', $order->goods_price);
                    # 记录环保豆日志
                    UserBeansLog::create([
                        'admin_id' => -1, // 系统
                        'beans' => $order->goods_price,
                        'source_type' => 1,
                        'beans_type' => 1,
                        'desc' => '聚合流量充值失败返还环保豆',
                        'rem_beans' => User::where('id', $order->user_id)->value('user_beans'),
                        'user_id' => $order->user_id
                    ]);
                    # 取消订单
                    $order->setOrderStatusCancel(); // 订单取消
                    # 记录日志
                    Log::info("聚合流量充值失败,查询流量订单返回数据:" . json_encode($resultTa, JSON_UNESCAPED_UNICODE));
                });
                # 发送模板消息
            }
        }
    }

    /**
     * 话费充值
     *
     * @param $order
     */
    public function virtualRechargeWithCall($order)
    {
        $call = new RechargeWithCall();

        # 发送聚合话费api请求
        $result = $call->sendJuheMobile($order);

        Log::info("话费充值接收数据:" . json_encode($result, JSON_UNESCAPED_UNICODE));

        # 验证是否充值成功
        if ($result['code'] == 1000 && isset($result['data']['error_code']) && $result['data']['error_code'] == JuheConstant::RECHARGE_OK) {
            # 充值中
            if ($result['data']['result']['game_state'] == '0') {
                $order->setOrderStatusDelivered(); // 已发货
            }
            # 查询订单
        } else {
            # 充值失败
            Db::transaction(function () use ($order, $result) {
                # 退还环保豆
                $user = User::find($order->user_id);
                $user->setInc('user_beans', $order->goods_price);
                # 记录环保豆日志
                UserBeansLog::create([
                    'admin_id' => -1, // 系统
                    'beans' => $order->goods_price,
                    'source_type' => 1,
                    'beans_type' => 1,
                    'desc' => '话费充值失败返还环保豆',
                    'rem_beans' => User::where('id', $order->user_id)->value('user_beans'),
                    'user_id' => $order->user_id
                ]);
                # 取消订单
                $order->setOrderStatusCancel(); // 订单取消
                # 记录日志
                Log::info("话费充值失败,返回数据:" . json_encode($result, JSON_UNESCAPED_UNICODE));
            });
            # 发送模板消息
        }
    }
}
<?php


namespace app\crontab\command;


use app\fmyadmin\model\AddressBlacklist;
use app\fmyadmin\model\Express;
use app\fmyadmin\model\Order;
use app\fmyadmin\model\OrderShipping;
use app\fmyadmin\model\SingleSystem;
use app\fmyadmin\model\SingleSystemRelation;
use app\fmyadmin\model\UserPhoneBlack;
use app\fmyadmin\model\Warehouse;
use app\fmyadmin\server\OrderStatisticServer;
use Carbon\Carbon;
use order\VerifyOrders;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;
use tool\FmyRedis;

class DistributorOrder extends Command
{
    /**
     * 配置
     */
    protected function configure()
    {
        $this->setName('DistributorOrder')->setDescription("系统分配订单");
    }

    /**
     * 执行分单
     *
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     */
    protected function execute(Input $input, Output $output)
    {
        # 记录日志
        Log::info('分单开始');
        # 获取旧衣回收默认应用
        $defaultSingleSystemId = SingleSystem::where([
            ['type', '=', 1],
            ['is_enable_default', '=', 1],
        ])->value('id');
        if (empty($defaultSingleSystemId)) {
            Log::error("匹配旧衣回收默认应用失败");
            die();
        }
        # 获取应用关联省
        $provinceAdcodeArr = SingleSystemRelation::where([
            ['system_id', '=', $defaultSingleSystemId], // 默认应用ID
            ['status', '=', 1], // 开启状态
            ['level', '=', 1], // 省份
        ])->column('adcode');

        # 分单规则分单(读表)
        foreach (Order::where([
            ['order_date', '=', Carbon::now()->toDateString()], // 当天
            ['order_start_time', '<', Carbon::now()->addSeconds(1800)->format('H:i')], // 起始时间小于等于当前时间 - 半小时
            ['status', '=', 1], // 等待确认
            ['shipping_task', '=', 0], // 非立即分配快递公司
            ['shipping_id', '=', 0], // 未分配快递公司
            ['estimate_weight', '<>', 5], // 旧衣回收
            //['estimate_weight', '<>', 15], // 旧书回收 5本以下
            ['estimate_weight', '<>', 16], // 玩具回收 3kg以下
            ['order_type', '<>', 3], // 排除家电回收
            ['order_type', '<>', 2], // 排除书籍回收
            ['province_code', 'in', $provinceAdcodeArr], // 旧衣回收默认应用开启的省份
        ])->whereNull('delete_time')->cursor() as $order) {
            try {
                Log::notice('订单ID:【' . $order->id . "】开始分单");

                # 订单规则验证
//                $verify = $order->verifyOrder();
//                if ($verify === false) {
//                    # 统计
//                    OrderStatisticServer::preCancelStatistic($order->order_date, $order->oauth_platform_id, $order->order_type);
//
//                    # 1.设置为预约取消
//                    $order->setPreCancelStatus();
//
//                    # 2.执行订单取消回调事件
//                    (new VerifyOrders($order))->orderCancel();
//
//                    Log::error("订单ID:【" . $order->id . "】订单验证规则验证失败");
//                    continue;
//                }

                # 应用匹配
                if (($systemId = $order->ruleMatchSingleSystem()) === false) {
                    Log::error("订单ID:【" . $order->id . "】,匹配应用失败");
                    continue;
                }

                # 匹配规则校验
                if (($rule = $order->ruleMatch($systemId)) === false) {
                    Log::error("订单ID:【" . $order->id . "】,匹配规则不存在，分单失败");
                    continue;
                }

                # 新增快递公司匹配:老数据只有仓库,没有快递公司
                if (!isset($rule['express_id']) || empty($rule['express_id'])) {
                    $expressId = Express::where('code', 'AIGUO')->value('id');
                    if (empty($expressId)) {
                        Log::error("订单ID:【" . $order->id . "】,匹配规则未分配快递公司，且未匹配到爱裹快递");
                        continue;
                    }
                    $rule['express_id'] = $expressId;
                }

                # 判断快递公司是否激活
                $express = Express::find($rule['express_id']);
                if ($express->isOpen() === false) {
                    Log::error("订单ID:【" . $order->id . "】,该订单分配的快递公司ID【" . $rule['express_id'] . "】未激活");
                    continue;
                }

                # 发送订单
                $warehouse = Warehouse::find($rule['warehouse_id']);
                $bool = $order->createOrder($express, $warehouse);

                if ($bool === false) {
                    Log::error("订单ID:【" . $order->id . "】,下单失败");
                    continue;
                }

                # 更新订单信息
                $order->setWarehouseId($rule['warehouse_id']); # 更新仓库ID
                $order->setExpressId($rule['express_id']); # 更新快递ID

                # 分单成功加入缓存
                // $order->storeOrderRedis();

                # 记录日志
                Log::info('订单ID:【' . $order->id . "】,分单成功");

            } catch (\Exception $exception) {
                Log::error('订单ID:【' . $order->id . "】分单失败");
                Log::error("分单失败【" . $order->id . "】数据:" . json_encode([
                        'code' => $exception->getCode(),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'message' => $exception->getMessage()
                    ], JSON_UNESCAPED_UNICODE));
                continue;
            }
        }
        # 记录日志
        Log::info('分单结束');
    }
}
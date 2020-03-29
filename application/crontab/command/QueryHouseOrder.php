<?php


namespace app\crontab\command;


use app\fmyadmin\model\HaOrderRelation;
use app\fmyadmin\model\OrderAction;
use app\fmyadmin\server\OrderRelationServer;
use Carbon\Carbon;
use HouseElectricity\ABL\OrderHandle;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Exception;
use think\facade\Log;

class QueryHouseOrder extends Command
{
    /**
     * 配置
     */
    protected function configure()
    {
        $this->setName('QueryHouseOrder')->setDescription("查询家电订单");
    }

    /**
     * 查询订单
     *
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function execute(Input $input, Output $output)
    {
        // 爱博绿订单状态:0 待接单 1 已接单 2 回收完成 3 取消订单 -1 未知状态

        # 记录日志
        Log::notice('查询家电订单开始');
        # 查询订单
        foreach (HaOrderRelation::where([
//            ['create_time', 'between', [Carbon::now()->startOfDay()->toDateTimeString(), Carbon::now()->endOfDay()->toDateTimeString()]], // 当天
            ['status', '=', 2], // 回收中
        ])->whereNull('delete_time')->cursor() as $haOrderRelation) {
            try {
                # 记录日志
                Log::notice("【家电回收】订单关联ID:【" . $haOrderRelation->id . "】,订单ID:【" . $haOrderRelation->order_id . "】查询开始");

                # 排除非爱博绿订单
                if (empty($haOrderRelation->send_sn) || (!empty($haOrderRelation->send_sn) && strpos($haOrderRelation->send_sn, 'abl') !== false)) {
                    $orderHandle = new OrderHandle($haOrderRelation);
                    $data = $orderHandle->queryOrder();
                    if ($data === false) {
                        throw new Exception('查询订单失败');
                    }
                    # 记录日志
                    Log::notice("【家电回收】订单关联ID:【" . $haOrderRelation->id . "】,订单ID:【" . $haOrderRelation->order_id . "】,返回数据:" . json_encode($data, true));

                    # 判断是否存在下单订单号
                    $sendSn = $data['orderno'] ?? "";
                    if (empty($haOrderRelation->send_sn)) {
                        $haOrderRelation->setSendSn($sendSn);
                    }

                    # 判断是否存在第三方订单号
                    $receiveSn = $data['serviceno'] ?? "";
                    if (empty($haOrderRelation->receive_sn)) {
                        $haOrderRelation->setReceiveSn($receiveSn);
                    }

                    # 订单逻辑处理
                    $this->updateOrder($haOrderRelation, $data);

                    # 更新合作企业订单信息
                    $haOrderRelation->order->updateAppPlatformInfo("", $receiveSn); // PS:以前的数据这个app_platfrom_id可能为空，但是child_sn可能有值
                } else {
                    throw new Exception('非爱博绿订单不查询');
                }

                # 记录日志
                Log::notice("【家电回收】订单关联ID:【" . $haOrderRelation->id . "】,订单ID:【" . $haOrderRelation->order_id . "】查询结束");

            } catch (Exception $exception) {
                Log::error("【家电回收】订单关联ID:【" . $haOrderRelation->id . "】,订单ID:【" . $haOrderRelation->order_id . "】,原因:" . $exception->getMessage() . ",行号:" . $exception->getLine());

                continue;
            }
        }
        # 记录日志
        Log::notice('查询家电订单结束');
    }

    /**
     * 订单逻辑处理
     *
     * @param HaOrderRelation $haOrderRelation
     * @param array $orderInfo
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function updateOrder(HaOrderRelation $haOrderRelation, array $orderInfo)
    {
        switch ($orderInfo['status']) {
            # 待接单
            case 0:
                # 记录订单操作日志
                OrderAction::storeOrderActionLog([
                    'order_id' => $haOrderRelation->order->id,
                    'action_type' => 1,
                    'action_user' => -1,
                    'start_order_status' => 2,
                    'end_order_status' => 2,
                    'action_note' => "爱博绿反馈待接单【" . $haOrderRelation->id . "】",
                    'status_desc' => '回收中->回收中'
                ]);
                break;
            # 已接单
            case 1:
                # 记录订单操作日志
                OrderAction::storeOrderActionLog([
                    'order_id' => $haOrderRelation->order->id,
                    'action_type' => 1,
                    'action_user' => -1,
                    'start_order_status' => 2,
                    'end_order_status' => 2,
                    'action_note' => "爱博绿反馈已接单【" . $haOrderRelation->id . "】",
                    'status_desc' => '回收中->回收中'
                ]);
                break;
            # 回收完成
            case 2:
                # 更新回收金额
                $fee = $orderInfo['amount'] ?? 0;
                $haOrderRelation = $haOrderRelation->setAmount($fee);
                # 设置订单已完成
                $haOrderRelation = $haOrderRelation->setCollectSuccessStatus();
                # 同步主订单实际费用
                $haOrderRelation->order->syncActualFee($fee);
                # 同步主订单状态
                $orderRelationServer = new OrderRelationServer();
                $orderRelationServer->syncOrderStatus($haOrderRelation);
                # 记录订单操作日志
                OrderAction::storeOrderActionLog([
                    'order_id' => $haOrderRelation->order->id,
                    'action_type' => 1,
                    'action_user' => -1,
                    'start_order_status' => 2,
                    'end_order_status' => 3,
                    'action_note' => "爱博绿反馈已完成【" . $haOrderRelation->id . "】，金额:" . $fee,
                    'status_desc' => '回收中->揽件成功'
                ]);
                break;
            # 取消订单
            case 3:
                # 更新状态为"揽收失败"并设置原因
                $cancelledReasonText = $orderInfo['cancelreason'] ?? "爱博绿取消";
                $haOrderRelation = $haOrderRelation->setCollectFailureStatus($cancelledReasonText);
                # 同步主订单状态
                $orderRelationServer = new OrderRelationServer();
                $orderRelationServer->syncOrderStatus($haOrderRelation);
                # 记录订单操作日志
                OrderAction::storeOrderActionLog([
                    'order_id' => $haOrderRelation->order->id,
                    'action_type' => 1,
                    'action_user' => -1,
                    'start_order_status' => 2,
                    'end_order_status' => 6,
                    'action_note' => "爱博绿反馈已取消【" . $haOrderRelation->id . "】",
                    'status_desc' => '回收中->揽件失败'
                ]);
                break;
            # 未知
            case -1:
                break;
            default:
                throw new Exception('订单状态不存在');
        }
    }
}
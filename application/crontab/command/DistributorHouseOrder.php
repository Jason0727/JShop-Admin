<?php


namespace app\crontab\command;

use app\fmyadmin\model\HaOrderRelation;
use app\fmyadmin\server\OrderRelationServer;
use Carbon\Carbon;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Exception;
use think\facade\Log;
use app\fmyadmin\model\AppointPlatform as AppointPlatformModel;

/**
 * 家电订单分单
 *
 * Class DistributorHouseOrder
 * @package app\crontab\command
 */
class DistributorHouseOrder extends Command
{
    const BUSINESS_ABL = "BUSINESS_ABL"; # 爱博绿
    const BUSINESS_HI = "BUSINESS_HI";# 嗨回收

    /**
     * 配置
     */
    protected function configure()
    {
        $this->setName('DistributorHouseOrder')->setDescription("系统分配家电订单");
    }

    /**
     * 分单
     *
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \ReflectionException
     */
    protected function execute(Input $input, Output $output)
    {
        # 记录日志
        Log::notice('分单家电订单开始');

        # 分单规则分单(读表)
        foreach (HaOrderRelation::where([
            ['status', '=', 1], // 等待确认&&分单时间由主订单预约时间限制(只分当天)
        ])->whereNull('delete_time')->cursor() as $haOrderRelation) {
            try {
                # 记录日志
                Log::notice("【家电回收】订单关联ID:【" . $haOrderRelation->id . "】,订单ID:【" . $haOrderRelation->order_id . "】开始分单");
                # 主订单状态校验:主订单为取消状态(揽件失败和预约取消)不支持分单
                if (in_array($haOrderRelation->order->status, [6, 7])) {
                    (new OrderRelationServer())->syncPreOrder($haOrderRelation, "主订单状态不满足分单要求");
                    throw new Exception("【家电回收】订单关联ID:【" . $haOrderRelation->id . "】,订单ID:【" . $haOrderRelation->order_id . "】,主订单状态不满足分单要求");
                }

                # 根据主订单预约时间进行分单
                if ($haOrderRelation->order->order_date != Carbon::now()->toDateString()) {
                    # 主订单预约时间为明天及以后，不执行预约取消
                    $orderDateTimestamp = Carbon::parse($haOrderRelation->order->order_date)->timestamp;
                    $todayTimestamp = Carbon::now()->startOfDay()->timestamp;
                    if ($orderDateTimestamp > $todayTimestamp) {
                        throw new Exception("【家电回收】订单关联ID:【" . $haOrderRelation->id . "】,订单ID:【" . $haOrderRelation->order_id . "】，预约日期:" . $haOrderRelation->order->order_date . "，当日日期:" . Carbon::now()->toDateString() . ",非当日订单，不在分单范围内【明天及之后】");
                    }
                    # 主订单预约时间为昨天及之前，执行预约取消
                    (new OrderRelationServer())->syncPreOrder($haOrderRelation, "主订单预约时间为昨天及之前");
                    throw new Exception("【家电回收】订单关联ID:【" . $haOrderRelation->id . "】,订单ID:【" . $haOrderRelation->order_id . "】，预约日期:" . $haOrderRelation->order->order_date . "，当日日期:" . Carbon::now()->toDateString() . ",非当日订单，不在分单范围内【昨天及之前】");
                }

                # 匹配应用
                if (($systemId = $haOrderRelation->order->ruleMatchSingleSystem()) === false) {
                    throw new Exception("【家电回收】订单关联ID:【" . $haOrderRelation->id . "】,订单ID:【" . $haOrderRelation->order_id . "】匹配应用失败");
                }

                # 匹配规则校验
                if (($rule = $haOrderRelation->order->ruleMatch($systemId)) === false || !isset($rule['appoint_id'])) {
                    throw new Exception("【家电回收】订单关联ID:【" . $haOrderRelation->id . "】,订单ID:【" . $haOrderRelation->order_id . "】匹配合作失败");
                }

                $model = AppointPlatformModel::where('id', $rule['appoint_id'])->value('use_model');

                # 反射类
                $class = new \ReflectionClass($model);
                $orderHandle = $class->newInstance($haOrderRelation);

                # 下单
                $result = $orderHandle->createOrder();

                # 下单失败
                if ($result === false) {
                    (new OrderRelationServer())->syncPreOrder($haOrderRelation, $orderHandle->getErrMsg());
                    throw new Exception($orderHandle->getErrMsg());
                }

                # 下单成功:更新状态"回收中" && 记录下单订单号和第三方订单号
                $returnData = $orderHandle->getReturnData();
                $haOrderRelation->status = HaOrderRelation::STATUS_RECEIVING;
                $haOrderRelation->send_sn = $returnData['send_sn'];
                $haOrderRelation->receive_sn = $returnData['receive_sn'];
                $haOrderRelation->save();

                # 同步主订单
                (new OrderRelationServer())->syncOrderStatus($haOrderRelation);

                # 更新合作企业订单信息
                $haOrderRelation->order->updateAppPlatformInfo($rule['appoint_id'], $returnData['receive_sn']);

                # 记录日志
                Log::notice("【家电回收】订单关联ID:【" . $haOrderRelation->id . "】订单ID:【" . $haOrderRelation->order_id . "】,分单成功");

            } catch (Exception $exception) {
                Log::notice("【家电回收】分单失败,订单关联ID:【" . $haOrderRelation->id . "】,订单ID:【" . $haOrderRelation->order_id . "】,原因:" . $exception->getMessage() . ",行号:" . $exception->getLine());

                continue;
            }
        }

        # 记录日志
        Log::notice('分单家电订单结束');
    }
}
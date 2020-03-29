<?php


namespace app\crontab\command;

use app\fmyadmin\model\OauthUser;
use app\fmyadmin\model\ScorePoolConfig;
use app\fmyadmin\model\ScorePoolUser;
use app\fmyadmin\model\TemplateMessage;
use app\fmyadmin\model\User;
use app\fmyadmin\model\UserBeansLog;
use Carbon\Carbon;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;
use tool\FmyRedis;

/**
 * 投注池奖励发放与失败消息推送
 *
 * Class ScorePool
 * @package app\crontab\command
 */
class ScorePool extends Command
{
    /**
     * 配置(描述)
     */
    protected function configure()
    {
        $this->setName('ScorePool')->setDescription("投注池奖励发放与失败消息推送");
    }

    /**
     * 执行
     *
     * @param Input $input
     * @param Output $output
     * @return bool|int|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    protected function execute(Input $input, Output $output)
    {
        Log::info("投注池奖励发放与失败消息推送 Start");

        # 日期
        $date = Carbon::now()->toDateString();
        # 投注池配置校验
        $scorePoolConfig = ScorePoolConfig::where('conf_date', $date)->find();
        if (empty($scorePoolConfig)) {
            Log::info("未设置投注池配置");
            return false;
        }

        # 校验投注配置是否截止
        $endDateTime = Carbon::parse($date . " " . $scorePoolConfig->end_time);
        if (time() < $endDateTime->timestamp) {
            Log::info("投注暂未结束");
            return false;
        }

        # 实际投注人数校验
        $actualJoin = $scorePoolConfig->actual_join;
        if ($actualJoin == 0) {
            Log::info("实际投注人数为0");
            return false;
        }

        # 投注池平台关联校验
        $scorePoolConfigRelation = $scorePoolConfig->scorePoolConfigRelation->toArray();
        if (empty($scorePoolConfigRelation)) {
            Log::info("投注池配置未关联任何平台");
            return false;
        }

        # 平台
        $platformArr = array_unique(array_column($scorePoolConfigRelation, 'platform_id'));

        # 奖励环保豆
        $stagScore = $scorePoolConfig->stag_score; // 单次投注数量
        $joinIn = $scorePoolConfig->join_in; // 总参与人数
        $subRate = $scorePoolConfig->sub_rate; // 平台补贴率
        // $rewardBeans = intval(ceil(($stagScore * $joinIn * (1 + $subRate / 100) - $stagScore * $actualJoin) / $actualJoin)); // 奖励环保豆
        // 奖励环保豆 = (单次投注数量 * 总参与人数 * (1 + 平台补贴率 / 100) - 单次投注数量 * 实际投注人数) / 实际投注人数
        $rewardBeans = intval(ceil($stagScore * $joinIn * $subRate / 100 / $actualJoin));
        # 投注且打卡发放奖励
        $this->sendReward($scorePoolConfig, $platformArr, $rewardBeans);

        # 投注未打卡推送消息
        $this->pushMessage($scorePoolConfig, $platformArr);

        Log::info("投注池奖励发放与失败消息推送 End");

    }

    /**
     * 投注且打卡发放奖励
     *
     * @param ScorePoolConfig $scorePoolConfig
     * @param array $platformArr
     * @param int $rewardBeans
     */
    private function sendReward(ScorePoolConfig $scorePoolConfig, array $platformArr, int $rewardBeans)
    {
        foreach (ScorePoolUser::where([
            ['conf_id', '=', $scorePoolConfig->id],
            ['platform_id', 'in', $platformArr],
            ['sign_status', '=', 1], // 已打卡
            ['push_status', 'not in', [3]], // 0 未推送 1 投注推送 2 投注打卡（返还奖励）推送 3 投注分发奖励（没有推送） 4投注未打卡推送
        ])->cursor() as $user) {
            # 用户加豆
            $user->user->setInc('user_beans', $rewardBeans); // 用户剩余环保豆
            $user->user->setInc('total_beans', $rewardBeans); // 累计获取环保豆
            # 修改投注推送状态
            $user->setSendRewardPushStatus();
            # 添加环保豆操作记录
            UserBeansLog::create([
                'admin_id' => 0, // 系统
                'beans' => $rewardBeans,
                'source_type' => 9,
                'beans_type' => 1,
                'desc' => "投注且打卡奖励环保豆",
                'rem_beans' => User::where('id', $user->uid)->value('user_beans'),
                'user_id' => $user->uid
            ]);
        }
    }

    /**
     * 投注未打卡推送消息
     *
     * @param ScorePoolConfig $scorePoolConfig
     * @param array $platformArr
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function pushMessage(ScorePoolConfig $scorePoolConfig, array $platformArr)
    {
        foreach (ScorePoolUser::where([
            ['conf_id', '=', $scorePoolConfig->id],
            ['platform_id', 'in', $platformArr],
            ['sign_status', '=', 0], // 未打卡
            ['push_status', 'not in', [4]], // 0 未推送 1 投注推送 2 投注打卡（返还奖励）推送 3 投注分发奖励（没有推送） 4投注未打卡推送
        ])->cursor() as $user) {
            # 修改投注推送状态
            $user->setUnsignedPushStatus();
            # 发送模板消息
            $this->sendTemplateMessage($user);
        }
    }

    /**
     * 发送模板消息
     *
     * @param ScorePoolUser $scorePoolUser
     * @param array $extraData
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function sendTemplateMessage(ScorePoolUser $scorePoolUser, array $extraData = [])
    {
        # 飞蚂蚁用户 不发送模板消息
        if ($scorePoolUser->uid == 1) return true;

        # 平台Oauth
        $oauth = $scorePoolUser->oauthPlatform->oauth;

        # 获取模板
        $templateMessageObj = TemplateMessage::getMessageTemplate($scorePoolUser->platform_id, 5, 'CLOCK_FAILED', 0);
        if (empty($templateMessageObj)) {
            Log::notice("投注未打卡推送消息，匹配模板失败");
            return false;
        }

        # 特殊数据处理
        # 主题
        $scorePoolUser->theme = "飞蚂蚁投注池";
        # 失败原因
        $scorePoolUser->failure_reason = "投注未打卡";

        # 发送模板消息
        switch ($oauth) {
            case 'qq':
                if (($params = $this->formatParamsWithQq($scorePoolUser, $extraData)) === false) return false;
                break;
            case 'toutiao':
                if (($params = $this->formatParamsWithToutiao($scorePoolUser, $extraData)) === false) return false;
                break;
            case 'baidu':
                if (($params = $this->formatParamsWithBaidu($scorePoolUser, $extraData)) === false) return false;
                break;
            case 'alipaysmall':
                if (($params = $this->formatParamsWithAlipaySmall($scorePoolUser, $extraData)) === false) return false;
                break;
            case 'weixinsmall':
                if (($params = $this->formatParamsWithWeiXinSmall($scorePoolUser, $extraData)) === false) return false;
                break;
            case 'weixin':
                if (($params = $this->formatParamsWithWeiXin($scorePoolUser, $extraData)) === false) return false;
                break;
            default:
                return false;
        }
        # 发送模板消息
        $result = sendTemplateMessage($templateMessageObj, $scorePoolUser, $params['openId'], $params['params']);
        Log::info("投注未打卡推送消息结果:" . json_encode([
                'result' => $result,
                'score_pool_user_id' => $scorePoolUser->id
            ], JSON_UNESCAPED_UNICODE));

    }

    /**
     * 获取用户openId 和 formId
     *
     * @param ScorePoolUser $scorePoolUser
     * @return array|bool
     */
    private function getOpenIdAndFormId(ScorePoolUser $scorePoolUser)
    {
        # 操作库
        $select = 2;
        # 前缀
        $prefix = "fmy_user_formid";
        # 获取用户ID
        $openId = OauthUser::getOpenId($scorePoolUser->uid, $scorePoolUser->platform_id);
        if (empty($openId)) {
            Log::notice("获取用户openId失败,投注配置ID:" . $scorePoolUser->id);
            return false;
        }
        # 获取formId
        $redis = new FmyRedis(['select' => $select]);
        $formId = $redis->lpop($prefix . ":" . $scorePoolUser->platform_id . ":" . $scorePoolUser->uid);
        if (empty($formId)) {
            Log::notice("获取formId失败,投注配置ID:" . $scorePoolUser->id . ";formId前缀:" . $prefix . ":" . $scorePoolUser->platform_id . ":" . $scorePoolUser->uid);
            return false;
        }

        return compact(['openId', 'formId']);
    }

    /**
     * 格式化QQ发送参数
     *
     * @param ScorePoolUser $scorePoolUser
     * @param array $extraData
     * @return array|bool
     */
    private function formatParamsWithQq(ScorePoolUser $scorePoolUser, array $extraData = [])
    {
        $openIdAndFormId = $this->getOpenIdAndFormId($scorePoolUser);

        if ($openIdAndFormId === false) return false;

        $data = [
            'openId' => $openIdAndFormId['openId'],
            'params' => [
                'page' => $extraData['page'] ?? "",
                'form_id' => $openIdAndFormId['formId'],
                'emphasis_keyword' => ''
            ]
        ];

        return $data;
    }

    /**
     * 格式化头条发送参数
     *
     * @param ScorePoolUser $scorePoolUser
     * @param array $extraData
     * @return array|bool
     */
    private function formatParamsWithTouTiao(ScorePoolUser $scorePoolUser, array $extraData = [])
    {
        $openIdAndFormId = $this->getOpenIdAndFormId($scorePoolUser);

        if ($openIdAndFormId === false) return false;

        $data = [
            'openId' => $openIdAndFormId['openId'],
            'params' => [
                'page' => $extraData['page'] ?? "",
                'form_id' => $openIdAndFormId['formId'],
            ]
        ];

        return $data;
    }

    /**
     * 格式化百度发送参数
     *
     * @param ScorePoolUser $scorePoolUser
     * @param array $extraData
     * @return array|bool
     */
    private function formatParamsWithBaidu(ScorePoolUser $scorePoolUser, array $extraData = [])
    {
        $openIdAndFormId = $this->getOpenIdAndFormId($scorePoolUser);

        if ($openIdAndFormId === false) return false;

        $data = [
            'openId' => $openIdAndFormId['openId'],
            'params' => [
                'touser' => $extraData['touser'] ?? "",
                'page' => $extraData['page'] ?? "",
                'scene_id' => $openIdAndFormId['formId'],
                'scene_type' => 1
            ]
        ];

        return $data;
    }

    /**
     * 格式化支付宝小程序参数
     * PS:支付宝小程序的page参数必传
     *
     * @param ScorePoolUser $scorePoolUser
     * @param array $extraData
     * @return array|bool
     */
    private function formatParamsWithAlipaySmall(ScorePoolUser $scorePoolUser, array $extraData = [])
    {
        $openIdAndFormId = $this->getOpenIdAndFormId($scorePoolUser);

        if ($openIdAndFormId === false) return false;

        $data = [
            'openId' => $openIdAndFormId['openId'],
            'params' => [
                'form_id' => $openIdAndFormId['formId'],
                'page' => $extraData['page'] ?? "page/component/index" // page 必传
            ]
        ];

        return $data;
    }

    /**
     * 微信小程序模板
     *
     * @param ScorePoolUser $scorePoolUser
     * @param array $extraData
     * @return array|bool
     */
    private function formatParamsWithWeiXinSmall(ScorePoolUser $scorePoolUser, array $extraData = [])
    {
        $openIdAndFormId = $this->getOpenIdAndFormId($scorePoolUser);

        if ($openIdAndFormId === false) return false;

        $data = [
            'openId' => $openIdAndFormId['openId'],
            'params' => [
                'form_id' => $openIdAndFormId['formId'],
                'page' => $extraData['page'] ?? "",
                'emphasis_keyword' => $extraData['emphasis_keyword'] ?? ""
            ]
        ];

        return $data;
    }

    /**
     * 格式化微信公众号参数
     *
     * @param ScorePoolUser $scorePoolUser
     * @param array $extraData
     * @return array|bool
     */
    private function formatParamsWithWeiXin(ScorePoolUser $scorePoolUser, array $extraData = [])
    {
        $openIdAndFormId = $this->getOpenIdAndFormId($scorePoolUser);

        if ($openIdAndFormId === false) return false;

        $data = [
            'openId' => $openIdAndFormId['openId'],
            'params' => [
                'url' => $extraData['url'] ?? "",
                'miniprogram' => $extraData['miniprogram'] ?? [],
            ]
        ];

        return $data;
    }
}
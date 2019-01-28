<?php

namespace SV\ReportImprovements\Service\WarningLog;

use SV\ReportImprovements\Entity\WarningLog;
use SV\ReportImprovements\Globals;
use XF\Entity\ThreadReplyBan;
use XF\Entity\Warning;
use XF\Mvc\Entity\Entity;
use XF\Service\AbstractService;
use XF\Service\ValidateAndSavableTrait;

/**
 * Class Creator
 *
 * @package SV\ReportImprovements\XF\Service\WarningLog
 */
class Creator extends AbstractService
{
    use ValidateAndSavableTrait;

    /**
     * @var Warning|\SV\ReportImprovements\XF\Entity\Warning
     */
    protected $warning;

    /**
     * @var ThreadReplyBan|\SV\ReportImprovements\XF\Entity\ThreadReplyBan
     */
    protected $threadReplyBan;

    /**
     * @var string
     */
    protected $operationType;

    /**
     * @var WarningLog
     */
    protected $warningLog;

    /** @var \SV\ReportImprovements\XF\Entity\Report */
    protected $report;

    /**
     * @var \XF\Service\Report\Creator|\SV\ReportImprovements\XF\Service\Report\Creator
     */
    protected $reportCreator;

    /**
     * @var \XF\Service\Report\Commenter|\SV\ReportImprovements\XF\Service\Report\Commenter
     */
    protected $reportCommenter;

    /** @var bool */
    protected $autoResolve;

    /** @var bool|null */
    protected $autoResolveNewReports = null;


    /**
     * Creator constructor.
     *
     * @param \XF\App $app
     * @param Entity  $content
     * @param         $operationType
     *
     * @throws \Exception
     */
    public function __construct(\XF\App $app, Entity $content, $operationType)
    {
        parent::__construct($app);

        if ($content instanceof Warning)
        {
            $this->warning = $content;
        }
        else if ($content instanceof ThreadReplyBan)
        {
            $this->threadReplyBan = $content;
        }
        else
        {
            throw new \LogicException('Unsupported content type provided.');
        }

        $this->operationType = $operationType;
        $this->setupDefaults();
    }

    /**
     * @param bool $autoResolve
     */
    public function setAutoResolve($autoResolve)
    {
        $this->autoResolve = (bool)$autoResolve;
    }

    /**
     * @param bool|null $autoResolve
     */
    public function setAutoResolveNewReports($autoResolve)
    {
        $this->autoResolveNewReports = (bool)$autoResolve;
    }

    /**
     * @return string[]
     */
    protected function getFieldsToLog()
    {
        return [
            'content_type',
            'content_id',
            'content_title',
            'user_id',
            'warning_id',
            'warning_date',
            'warning_user_id',
            'warning_definition_id',
            'title',
            'notes',
            'points',
            'expiry_date',
            'is_expired',
            'extra_user_group_ids',
        ];
    }

    /**
     * @throws \Exception
     */
    protected function setupDefaults()
    {
        $this->warningLog = $this->em()->create('SV\ReportImprovements:WarningLog');
        $warningLog = $this->warningLog;
        $warningLog->operation_type = $this->operationType;
        $warningLog->warning_edit_date = $this->operationType === 'new' ? 0 : \XF::$time;

        $report = null;
        if ($this->warning)
        {
            $report = $this->setupDefaultsForWarning();
        }
        else if ($this->threadReplyBan)
        {
            $report = $this->setupDefaultsForThreadReplyBan();
        }

        /** @var \SV\ReportImprovements\XF\Entity\ReportComment|null $comment */
        $comment = null;
        if ($this->reportCommenter)
        {
            $comment = $this->reportCommenter->getComment();
        }
        else if ($this->reportCreator)
        {
            $comment = $this->reportCreator->getComment();
        }

        if ($comment)
        {
            $comment->warning_log_id = $warningLog->getDeferredPrimaryId();
            $comment->hydrateRelation('WarningLog', $warningLog);
            if ($report)
            {
                $comment->hydrateRelation('Report', $report);
            }
        }
    }

    /**
     * @return \SV\ReportImprovements\XF\Entity\Report|\XF\Entity\Report|null
     */
    protected function setupDefaultsForWarning()
    {
        $warningLog = $this->warningLog;
        $warning = $this->warning;
        $report = $warning->Report;

        foreach ($this->getFieldsToLog() AS $field)
        {
            if ($warning->offsetExists($field))
            {
                $fieldValue = $warning->get($field);
                $warningLog->set($field, $fieldValue);
            }
        }

        if ($report)
        {
            $this->reportCommenter = $this->service('XF:Report\Commenter', $report);
        }
        else if (!$report && $this->app->options()->sv_report_new_warnings && $warning->Content)
        {
            $this->reportCreator = $this->service('XF:Report\Creator', $warning->content_type, $warning->Content);
            $report = $this->reportCreator->getReport();
        }

        return $report;
    }

    /**
     * @return \SV\ReportImprovements\XF\Entity\Report|null
     */
    protected function setupDefaultsForThreadReplyBan()
    {
        $warningLog = $this->warningLog;
        $threadReplyBan = $this->threadReplyBan;
        $warningLog->warning_date = \XF::$time;

        $report = $threadReplyBan->Report;
        $content = $threadReplyBan->User;
        $contentTitle = $threadReplyBan->User->username;

        $post = $threadReplyBan->Post;
        if ($post)
        {
            $report = $post->Report;
            $content = $post;
            $contentTitle = \XF::phrase('post_in_thread_x', [
                'title' => $post->Thread->title
            ])->render('raw');
        }

        $warningLog->content_type = $content->getEntityContentType();
        $warningLog->content_id = $content->getExistingEntityId();
        $warningLog->content_title = $contentTitle;
        $warningLog->expiry_date = $threadReplyBan->expiry_date;
        $warningLog->is_expired = $threadReplyBan->expiry_date > \XF::$time;
        $warningLog->reply_ban_thread_id = $threadReplyBan->thread_id;
        $warningLog->reply_ban_post_id = $content instanceof \XF\Entity\Post ? $content->getEntityId() : 0;
        $warningLog->user_id = $threadReplyBan->user_id;
        $warningLog->warning_user_id = \XF::visitor()->user_id;
        $warningLog->warning_definition_id = null;
        $warningLog->title = \XF::phrase('svReportImprov_reply_banned')->render('raw');
        $warningLog->notes = $warningLog->getReplyBanLink() . "\n" . $threadReplyBan->reason;

        if ($report)
        {
            $this->reportCommenter = $this->service('XF:Report\Commenter', $report);
        }
        else if (!$report)
        {
            $this->reportCreator = $this->service('XF:Report\Creator', $content->getEntityContentType(), $content);
            $report = $this->reportCreator->getReport();
        }

        return $report;
    }

    /**
     * @return WarningLog
     */
    public function getWarningLog()
    {
        return $this->warningLog;
    }

    /**
     * @return \SV\ReportImprovements\XF\Entity\Report
     */
    public function getReport()
    {
        return $this->report;
    }

    /**
     * @return array
     */
    protected function _validate()
    {
        $showErrorException = function ($errorFor, array $errors, array &$errorOutput)
        {
            if (\count($errors))
            {
                foreach($errors as $key => $error)
                {
                    if ($error instanceof \XF\Phrase)
                    {
                        $error = $error->render('raw');
                    }
                    if (\is_numeric($key))
                    {
                        $errorOutput[] = "{$errorFor}: {$error}";
                    }
                    else
                    {
                        $errorOutput[] = "{$errorFor}-{$key}: {$error}";
                    }
                }
            }
        };

        $oldVal = Globals::$allowSavingReportComment;
        Globals::$allowSavingReportComment = true;
        try
        {
            $this->warningLog->preSave();
            $warningLogErrors = $this->warningLog->getErrors();
            $reportCreatorErrors = [];
            $reportCommenterErrors = [];

            if ($this->reportCreator)
            {
                $this->reportCreator->validate($reportCreatorErrors);
            }
            else if ($this->reportCommenter)
            {
                $this->reportCommenter->validate($reportCommenterErrors);
            }
        }
        finally
        {
            Globals::$allowSavingReportComment = $oldVal;
        }
        $errorOutput = [];
        $showErrorException('Warning log', $warningLogErrors, $errorOutput);
        $showErrorException('Report', $reportCreatorErrors, $errorOutput);
        $showErrorException('Report comment', $reportCommenterErrors, $errorOutput);
        if ($errorOutput)
        {
            if ($this->warning)
            {
                \array_unshift($errorOutput, "Warning:{$this->warning->warning_id}");
            }
            throw new \RuntimeException(join(", \n", $errorOutput));
        }

        return [];
    }

    /**
     * @param \XF\Entity\Report $report
     * @return bool
     */
    protected function wasClosed(\XF\Entity\Report $report)
    {
        $reportState = $report->getPreviousValue('report_state');
        return $reportState === 'resolved' || $reportState === 'rejected';
    }

    /**
     * @return WarningLog
     * @throws \XF\PrintableException
     * @throws \Exception
     */
    protected function _save()
    {
        $this->db()->beginTransaction();

        $this->warningLog->save(true, false);
        if ($this->reportCreator)
        {
            $this->_saveReport();
            $this->reportCreator->save();
        }
        else if ($this->reportCommenter)
        {
            $this->_saveReportComment();
            $this->reportCommenter->save();
        }

        $this->db()->commit();

        return $this->warningLog;
    }

    protected function _saveReport()
    {
        $autoResolve = $this->autoResolve;
        if ($this->autoResolveNewReports != null)
        {
            $autoResolve = $this->autoResolveNewReports;
        }

        /** @var \SV\ReportImprovements\XF\Entity\ReportComment $comment */
        $comment = $this->reportCreator->getCommentPreparer()->getComment();
        $report = $this->report = $this->reportCreator->getReport();
        $resolveState = $autoResolve && !$this->wasClosed($report) ? 'resolved' : '';
        $comment->bulkSet([
            'warning_log_id' => $this->warningLog->warning_log_id,
            'is_report'      => false,
            'state_change'   => $resolveState,
        ], ['forceSet' => true]);

        if ($resolveState)
        {
            $report->set('report_state', $resolveState, ['forceSet' => true]);
            // if Report Centre Essentials is installed, then mark this as an autoreport
            if (isset($report->structure()->columns['autoreported']))
            {
                $report->set('autoreported', true, ['forceSet' => true]);
            }
        }
    }

    protected function _saveReportComment()
    {
        /** @var \SV\ReportImprovements\XF\Entity\ReportComment $comment */
        $comment = $this->reportCommenter->getComment();
        $report = $this->report = $comment->Report;

        $comment->bulkSet([
            'warning_log_id' => $this->warningLog->warning_log_id,
            'is_report'      => false,
        ], ['forceSet' => true]);

        if ($this->autoResolve && !$this->wasClosed($report))
        {
            $comment->set('state_change', 'resolved', ['forceSet' => true]);
            $report->set('report_state', 'resolved', ['forceSet' => true]);
            $comment->addCascadedSave($report);
        }
        else
        {
            $comment->set('state_change', '', ['forceSet' => true]);
            $report->set('report_state', $report->getPreviousValue('report_state'), ['forceSet' => true]);
            $report->set('assigned_user_id', $report->getPreviousValue('assigned_user_id'), ['forceSet' => true]);
        }
    }

    /**
     * @throws \Exception
     */
    public function sendNotifications()
    {
        if ($this->reportCreator)
        {
            $this->reportCreator->sendNotifications();
        }
        else if ($this->reportCommenter)
        {
            $this->reportCommenter->sendNotifications();
        }
    }
}
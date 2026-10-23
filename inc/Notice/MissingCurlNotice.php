<?php

namespace Inpsyde\BackWPup\Notice;

/**
 * Warns on BackWPup pages when the PHP cURL extension is missing.
 *
 * Several storage destinations (S3, Rackspace, SugarSync) and parts of the job
 * runner need cURL, so the user should know why they are unavailable.
 */
class MissingCurlNotice extends Notice
{
    /**
     * @var string
     */
    public const ID = 'backwpup_notice_missing_curl';

    /**
     * {@inheritdoc}
     */
    protected function render(NoticeMessage $message): void
    {
        $this->view->warning($message, $this->getDismissActionUrl());
    }

    /**
     * {@inheritdoc}
     */
    protected function isScreenAllowed(): bool
    {
        $screen = get_current_screen();
        if (!$screen instanceof \WP_Screen) {
            return false;
        }

        return strpos((string) $screen->id, 'backwpup') !== false;
    }

    /**
     * {@inheritdoc}
     */
    protected function shouldDisplay(): bool
    {
        if (function_exists('curl_init') && function_exists('curl_exec')) {
            return false;
        }

        return parent::shouldDisplay();
    }

    /**
     * {@inheritdoc}
     */
    protected function message(): NoticeMessage
    {
        return new NoticeMessage('missing_curl');
    }
}

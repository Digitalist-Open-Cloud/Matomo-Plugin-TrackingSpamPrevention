<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\TrackingSpamPrevention;

use Piwik\Common;
use Piwik\Log;
use Piwik\Mail;
use Piwik\Piwik;
use Piwik\SettingsPiwik;
use Piwik\View;

class BanIpNotificationEmail
{
    public function send($ip, $email, $maxActionsAllowed, $locationData, $nowDateTime)
    {
        if (empty($email) || !Piwik::isValidEmailString($email)) {
            return;
        }

        $mail = new Mail();
        $mail->addTo($email);
        $mail->setSubject(Piwik::translate('TrackingSpamPrevention_BanIpNotificationMailSubject'));
        $mail->setDefaultFromPiwik();

        $mailBody = 'This is for your information. The following IP was banned because visit tried to track more than '.$maxActionsAllowed.' actions:';
        $mailBody.='<br> "'.$ip.'" <br>';
        $instanceId = SettingsPiwik::getPiwikInstanceId();


        if (!empty($_GET)) {
            $get = $_GET;
            if (isset($get['token_auth'])) {
                $get['token_auth'] = 'XYZANONYMIZED';
            }
        } else {
            $get = [];
        }

        if (!empty($_POST)) {
            $post = $_POST;
            if (isset($post['token_auth'])) {
                $post['token_auth'] = 'XYZANONYMIZED';
            }
        } else {
            $post = [];
        }

        if (!empty($instanceId)) {
            $mailBody.='Current date (UTC): '.$nowDateTime.'
                        <br> IP as detected in header: '.\Piwik\IP::getIpFromHeader().'
                        <br> GET request info: '.json_encode($get, JSON_HEX_APOS).'
                        <br> POST request info: '.json_encode($post, JSON_HEX_APOS);
        }

        if(!empty($locationData)) {
            $mailBody.='<br> '.json_encode($locationData, JSON_HEX_APOS);
        }

        $mail->setBodyHtml(Common::sanitizeInputValue($mailBody));

        $testMode = (defined('PIWIK_TEST_MODE') && PIWIK_TEST_MODE);
        if ($testMode) {
            Log::info($mail->getSubject() .':' . $mail->getBodyText());
        } else {
            $mail->send();
        }

        return $mail->getBodyText();
    }

}

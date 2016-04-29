<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Forix\SettingNotification\Model\System\Config\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class AroundSave extends \Magento\Framework\DataObject
{
    /**
     * Setting Status Setting Notification Module
    */
    const XML_PATH_SETTING_NOTIFICATION_STATUS = 'setting_notification/general/enabled';

    /**
     * Recipient email config path
    */
    const XML_PATH_EMAIL_RECIPIENT = 'setting_notification/general/recipient_email';

    /**
     * Sender email config path
     */
    const XML_PATH_EMAIL_SENDER = 'setting_notification/general/sender_email';

    /**
     * CopyTo email config path
     */
    const XML_PATH_COPY_TO_EMAIL = 'setting_notification/general/copy_to';

    /**
     * CopyTo email method config path
     */
    const XML_PATH_COPY_TO_EMAIL_METHOD = 'setting_notification/general/copy_method';

    /**
     * Origin Config
     *
     * @var
     */
    protected   $_oldConfig;

    /**
     * Changed config
     * @var
     */
    protected   $_changedConfig;

    /**
     * @var \Magento\Config\Model\Config\Loader
     */
    protected   $_configLoader;

    /**
     * @var \Magento\Framework\Mail\Template\TransportBuilder
     */
    protected $_transportBuilder;

    /**
     * @var \Magento\Framework\Translate\Inline\StateInterface
     */
    protected $_inlineTranslation;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    public function __construct(
        \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder,
        \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation,
        ScopeConfigInterface $scopeConfig,
        \Magento\Config\Model\Config\Loader $configLoader,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        array $data = []
    )
    {
        parent::__construct($data);
        $this->_scopeConfig = $scopeConfig;
        $this->_configLoader = $configLoader;
        $this->_storeManager = $storeManager;
        $this->_transportBuilder = $transportBuilder;
        $this->_inlineTranslation = $inlineTranslation;
    }

    /**
     * Around save handler
     *
     * @param \Magento\Config\Model\Config $subject
     * @param \Closure $proceed
     *
     * @return mixed
     */

    public function aroundSave(\Magento\Config\Model\Config $subject, \Closure $proceed)
    {
        $settingNotificationEnable = $this->_scopeConfig->getValue(self::XML_PATH_SETTING_NOTIFICATION_STATUS, ScopeInterface::SCOPE_STORE);
        if ($settingNotificationEnable) {
            $this->_inlineTranslation->suspend();
            $this->initScope();

            $this->_oldConfig = $this->_configLoader->getConfigByPath(
                $subject->getSection(),
                $this->getScope(),
                $this->getScopeId(),
                false
            );
        }

        $returnValue = $proceed();

        if ($returnValue && $settingNotificationEnable) {
            $this->_changedConfig = $subject->load();

            $senderIndentifier = $this->_scopeConfig->getValue(self::XML_PATH_EMAIL_SENDER, ScopeInterface::SCOPE_STORE);
            $sender = [
                'name' => $this->_scopeConfig->getValue('trans_email/ident_'.$senderIndentifier.'/name', ScopeInterface::SCOPE_STORE),
                'email' => $this->_scopeConfig->getValue('trans_email/ident_'.$senderIndentifier.'/email', ScopeInterface::SCOPE_STORE),
            ];

            $recipientEmail = $this->_scopeConfig->getValue(self::XML_PATH_EMAIL_RECIPIENT, ScopeInterface::SCOPE_STORE);

            $copyTo = $this->getEmailCopyTo();
            $copyMethod = $this->_scopeConfig->getValue(self::XML_PATH_COPY_TO_EMAIL_METHOD, ScopeInterface::SCOPE_STORE);

            $changeConfigData = '';
            foreach ($this->_oldConfig as $key => $value) {
                if ($this->_oldConfig[$key] !== $this->_changedConfig[$key]) {
                    $changeConfigData .= '['.$key.'] has been changed from '.$this->_oldConfig[$key].' to '.$this->_changedConfig[$key].'<br />';
                }
            }

            if (!empty($copyTo) && $copyMethod == 'bcc') {
                $this->_transportBuilder->setTemplateIdentifier('setting_notification_email_email_template') // this code we have mentioned in the email_templates.xml
                ->setTemplateOptions(
                    [
                        'area' => \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE,
                        'store' => \Magento\Store\Model\Store::DEFAULT_STORE_ID
                    ]
                )
                    ->setTemplateVars(['data' => $changeConfigData])
                    ->setFrom($sender)
                    ->addTo($recipientEmail);
                foreach ($copyTo as $email) {
                    $this->_transportBuilder->addBcc($email);
                }
                $transport = $this->_transportBuilder->getTransport();
                $transport->sendMessage();
            }else{
                $transport = $this->_transportBuilder->setTemplateIdentifier('setting_notification_email_email_template') // this code we have mentioned in the email_templates.xml
                ->setTemplateOptions(
                    [
                        'area' => \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE,
                        'store' => \Magento\Store\Model\Store::DEFAULT_STORE_ID
                    ]
                )
                    ->setTemplateVars(['data' => $changeConfigData])
                    ->setFrom($sender)
                    ->addTo($recipientEmail)
                    ->getTransport();
                $transport->sendMessage();
            }

            if (!empty($copyTo) && $copyMethod == 'copy') {
                foreach ($copyTo as $email) {
                    $transport = $this->_transportBuilder->setTemplateIdentifier('setting_notification_email_email_template') // this code we have mentioned in the email_templates.xml
                    ->setTemplateOptions(
                        [
                            'area' => \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE,
                            'store' => \Magento\Store\Model\Store::DEFAULT_STORE_ID
                        ]
                    )
                        ->setTemplateVars(['data' => $changeConfigData])
                        ->setFrom($sender)
                        ->addTo($email)
                        ->getTransport();
                    $transport->sendMessage();
                }
            }

            $this->_inlineTranslation->resume();
        }

        return $returnValue;
    }

    /**
     * Get scope name and scopeId
     * @todo refactor to scope resolver
     * @return void
     */
    private function initScope()
    {
        if ($this->getSection() === null) {
            $this->setSection('');
        }
        if ($this->getWebsite() === null) {
            $this->setWebsite('');
        }
        if ($this->getStore() === null) {
            $this->setStore('');
        }

        if ($this->getStore()) {
            $scope = 'stores';
            $store = $this->_storeManager->getStore($this->getStore());
            $scopeId = (int)$store->getId();
            $scopeCode = $store->getCode();
        } elseif ($this->getWebsite()) {
            $scope = 'websites';
            $website = $this->_storeManager->getWebsite($this->getWebsite());
            $scopeId = (int)$website->getId();
            $scopeCode = $website->getCode();
        } else {
            $scope = 'default';
            $scopeId = 0;
            $scopeCode = '';
        }
        $this->setScope($scope);
        $this->setScopeId($scopeId);
        $this->setScopeCode($scopeCode);
    }

    /**
     * Return email copy_to list
     *
     * @return array|bool
     */
    public function getEmailCopyTo()
    {
        $data = $this->_scopeConfig->getValue(self::XML_PATH_COPY_TO_EMAIL, ScopeInterface::SCOPE_STORE);
        if (!empty($data)) {
            return explode(',', $data);
        }
        return false;
    }

}

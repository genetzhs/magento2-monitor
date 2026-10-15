<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\Store;

/**
 * The module's emails, sent through the store's own mail setup. One template
 * carries a heading, an intro and a list of rows; nothing in it loads from
 * outside when the email is opened.
 */
class Mailer
{
    public const TEMPLATE = 'magesentinel_monitor_notice';

    /** @var TransportBuilder */
    private $transportBuilder;
    /** @var ScopeConfigInterface */
    private $config;
    /** @var Escaper */
    private $escaper;

    public function __construct(TransportBuilder $transportBuilder, ScopeConfigInterface $config, Escaper $escaper)
    {
        $this->transportBuilder = $transportBuilder;
        $this->config = $config;
        $this->escaper = $escaper;
    }

    public function recipient(): string
    {
        $email = trim((string)$this->config->getValue('magesentinel/alerts/email'));
        return $email !== '' ? $email : (string)$this->config->getValue('trans_email/ident_general/email');
    }

    /**
     * @param array<int, array{label:string, detail:string, state:string}> $rows
     */
    public function send(string $subject, string $heading, string $intro, array $rows, string $footer): bool
    {
        $to = $this->recipient();
        if ($to === '') {
            return false;
        }
        $colors = ['bad' => '#d63b3b', 'watch' => '#d07a1f', 'ok' => '#1f9d6a', 'unknown' => '#8a8f98'];
        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr><td style="padding:10px 0;border-top:1px solid #e6e8ee;vertical-align:top;width:14px">'
                . '<span style="display:inline-block;width:10px;height:10px;border-radius:5px;margin-top:5px;background:'
                . ($colors[$row['state']] ?? $colors['unknown']) . '"></span></td>'
                . '<td style="padding:10px 0 10px 10px;border-top:1px solid #e6e8ee"><strong>'
                . $this->escaper->escapeHtml($row['label']) . '</strong><br><span style="color:#4a4f5c">'
                . $this->escaper->escapeHtml($row['detail']) . '</span></td></tr>';
        }
        try {
            $this->transportBuilder
                ->setTemplateIdentifier(self::TEMPLATE)
                ->setTemplateOptions(['area' => Area::AREA_ADMINHTML, 'store' => Store::DEFAULT_STORE_ID])
                ->setTemplateVars([
                    'subject' => $subject,
                    'heading' => $this->escaper->escapeHtml($heading),
                    'intro' => $this->escaper->escapeHtml($intro),
                    'rows' => $html,
                    'footer' => $this->escaper->escapeHtml($footer),
                ])
                ->setFromByScope('general')
                ->addTo($to)
                ->getTransport()
                ->sendMessage();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

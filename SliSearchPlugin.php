<?php
/*
 * ZenMagick - Smart e-commerce
 * Copyright (C) 2006-2012 zenmagick.org
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
namespace ZenMagick\plugins\sliSearch;

use ZenMagick\Base\Plugins\Plugin;
use ZenMagick\Http\Request;
use ZenMagick\Http\View\TemplateView;

/**
 * SLI Search plugin.
 *
 * <p>Adds all things required to use the SLI Systems search.
 *
 * @author DerManoMann <mano@zenmagick.org>
 */
class SliSearchPlugin extends Plugin
{
    private $order;

    /**
     * Create new instance.
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->order = null;
    }

    /**
     * Content processing callback.
     */
    public function onFinaliseContent($event)
    {
        $content = $event->getArgument('content');

        $header = $this->getRichAutoCompleteHeader();
        $footer = $this->getRichAutoCompleteFooter().$this->getConversionTracker();

        if ($this->get('debug')) {
            $header = str_replace('</script>', '/script-->', str_replace('<script', '<!--script', $header));
            $footer = str_replace('</script>', '/script-->', str_replace('<script', '<!--script', $footer));
        }

        $content = preg_replace('/<\/head>/', $header . '</head>', $content, 1);
        $content = preg_replace('/<\/body>/', $footer . '</body>', $content, 1);
        $event->setArgument('content', $content);
    }

    /**
     * Create rich auto complete header.
     *
     * @return string The rich auto complete header code to inject.
     */
    protected function getRichAutoCompleteHeader()
    {
        if (!$this->get('rac')) {
            return '';
        }
        $clientName = $this->get('clientName');
        $racVersion = $this->get('racVersion');
        $racRevision = $this->get('racRevision');
        $code = <<<EOT
<script language="javascript" type="text/javascript">
var sliJsHost = (("https:" == document.location.protocol) ? "https://" : "http://");
document.write(unescape('%3Clink rel="stylesheet" type="text/css" href="' + sliJsHost + 'assets.resultspage.com/js/rac/sli-rac.$racVersion.css" /%3E'));
document.write(unescape('%3Clink rel="stylesheet" type="text/css" href="' + sliJsHost + '$clientName.resultspage.com/rac/sli-rac.css?rev=$racRevision" /%3E'));
</script>
EOT;

        return $code;
    }

    /**
     * Create rich auto complete footer.
     *
     * @return string The rich auto complete footer code to inject.
     */
    protected function getRichAutoCompleteFooter()
    {
        if (!$this->get('rac')) {
            return '';
        }
        $clientName = $this->get('clientName');
        $racRevision = $this->get('racRevision');
        $code = <<<EOT
<script language="javascript" type="text/javascript">
var sliJsHost = (("https:" == document.location.protocol) ? "https://"  : "http://" );
document.write(unescape('%3Cscript src="' + sliJsHost + '$clientName.resultspage.com/rac/sli-rac.config.js?rev=$racRevision" type="text/javascript"%3E%3C/script%3E'));
</script>
EOT;

        return $code;
    }

    /**
     * Start view callback.
     */
    public function onViewStart($event)
    {
        $request = $event->getArgument('request');
        if ('checkout_success' == $request->getRequestId() && $event->hasArgument('view') && null != ($view = $event->getArgument('view')) && $view instanceof TemplateView) {
            $context = $view->getVariables();
            if (array_key_exists('currentOrder', $context)) {
                $this->order = $context['currentOrder'];
            }
        }
        $this->setDataCookie($event->getArgument('request'));
    }

    /**
     * Create conversion tracker code.
     *
     * @return string The conversion tracker code.
     */
    protected function getConversionTracker()
    {
        if (!$this->get('conversionTracker') || null == $this->order) {
            return '';
        }
        if (null == $this->order) {
            return '';
        }
        $order = $this->order;
        $itemLineTemplate = 'spark.addItem("%s", "%s", "%s");';
        $itemLines = '';
        foreach ($this->order->getOrderItems() as $orderItem) {
            $identifier = 'model' == $this->get('identifier') ? $orderItem->getModel() : $orderItem->getProductId();
            $name = $orderItem->getName();
            $price = number_format($orderItem->getCalculatedPrice(), 2, '.', '');
            $qty = $orderItem->getQuantity();
            $itemLines .= sprintf($itemLineTemplate, $identifier, $qty, $price);
        }
        $clientId = $this->get('clientId');
        $orderId = $order->getId();
        $accountId = $order->getAccountId();

        // totals
        $totalValue = number_format($this->order->getOrderTotalLineAmountForType('total'), 2, '.', '');
        $taxValue = number_format($this->order->getOrderTotalLineAmountForType('tax'), 2, '.', '');
        $shippingValue = number_format($this->order->getOrderTotalLineAmountForType('shipping'), 2, '.', '');

        $code = <<<EOT
<script type="text/javascript">
var sliSparkJsHost = (("https:" == document.location.protocol) ? "https://" : "http://");
document.write(unescape("%3Cscript src='" + sliSparkJsHost + "b.sli-spark.com/sli-spark.js' type='text/javascript'%3E%3C/script%3E"));
</script>
<script language="javascript" type="text/javascript">
var spark= new SliSpark("$clientId", "1");
spark.setPageType("checkout-confirmation");
spark.addTransaction("$orderId", "$accountId", "$totalValue", "$shippingValue", "$taxValue");
$itemLines;
spark.writeTrackCode();
spark.writeTransactionCode();
</script>
EOT;

        return $code;
    }

    /**
     * Set the sli data cookie.
     *
     * @param zenmagick\http\Request request The current request.
     */
    protected function setDataCookie(Request $request)
    {
        $languageCode = null != ($language = $request->getSession()->getLanguage()) ? $language->getCode() : '';
        $cartCount = count($request->getShoppingCart()->getItems());
        $currencyCode = $request->getSession()->getCurrencyCode();
        $data = array(
            'ut' => $request->getSession()->getType(),
            'sc' => $cartCount,
            'lang' => $languageCode,
            'cur' => $currencyCode
        );
        setrawcookie('zm_sli_data', http_build_query($data), 0, '/', $this->get('cookieDomain'));
    }

}

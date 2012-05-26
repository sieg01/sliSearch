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
namespace zenmagick\plugins\sliSearch;

use Plugin;
use zenmagick\base\Runtime;
use zenmagick\http\view\TemplateView;

/**
 * SLI Search plugin.
 *
 * <p>Adds all things required to use the SLI Systems search.
 *
 * @author DerManoMann <mano@zenmagick.org>
 */
class SliSearchPlugin extends Plugin {
    private $order;

    /**
     * Create new instance.
     */
    public function __construct() {
        parent::__construct();
        $this->setContext('storefront');
        $this->order = null;
    }


    /**
     * {@inheritDoc}
     */
    public function install() {
        parent::install();

        // include catalog feed?

        $this->addConfigValue('Client Id', 'clientId', '', 'SLI Systems client id for this site.');
        $this->addConfigValue('Client Name', 'clientName', '', 'SLI Systems client name for this site.');
        $this->addConfigValue('Search Domain', 'searchDomain', '', 'Domain name for search results.');
        $this->addConfigValue('Conversion Tracker', 'conversionTracker', 'false', 'Enable SLI Systems conversion tracker.',
            'widget@booleanFormWidget#name=conversionTracker&default=false&label=Conversion Tracker');
        $this->addConfigValue('Product identifier', 'identifier', 'productId', 'Select whether to use productId or model to identify products',
            'widget@selectFormWidget#name=identifier&options='.urlencode('productId=Product Id&model=Model'));
        // spark key / version?
        $this->addConfigValue('Ajax Search', 'ajaxSearch', 'false', 'Enable Ajax Search support.',
            'widget@booleanFormWidget#name=ajaxSearch&default=false&label=Ajax Search');
        $this->addConfigValue('Rich Auto Complete', 'richAutoComplete', 'false', 'Enable Rich Auto Complete support.',
            'widget@booleanFormWidget#name=richAutoComplete&default=false&label=Rich Auto Complete');
        // rac version, rac revision

        $this->addConfigValue('Debug', "debug", 'true', 'Generate code, but make inactive.',
            'widget@booleanFormWidget#name=debug&default=true&label=Debug&style=checkbox');
    }

    /**
     * {@inheritDoc}
     */
    public function init() {
        parent::init();
        $eventDispatcher = $this->container->get('eventDispatcher');
        $eventDispatcher->listen($this);
    }

    /**
     * Content processing callback.
     */
    public function onFinaliseContent($event) {
        $content = $event->get('content');

        $header = $this->getRichAutoCompleteHeader();
        $footer = $this->getRichAutoCompleteFooter().$this->getConversionTracker();

        if ($this->get('debug')) {
            $header = str_replace('</script>', '/script-->', str_replace('<script', '<!--script', $header));
            $footer = str_replace('</script>', '/script-->', str_replace('<script', '<!--script', $footer));
        }

        $content = preg_replace('/<\/head>/', $header . '</head>', $content, 1);
        $content = preg_replace('/<\/body>/', $footer . '</body>', $content, 1);
        $event->set('content', $content);
    }

    /**
     * Create rich auto complete header.
     *
     * @return string The rich auto complete header code to inject.
     */
    protected function getRichAutoCompleteHeader() {
        if (!$this->get('richAutoComplete')) {
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
    protected function getRichAutoCompleteFooter() {
        if (!$this->get('richAutoComplete')) {
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
    public function onViewStart($event) {
        $request = $event->get('request');
        if ('checkout_success' == $request->getRequestId() && $event->has('view') && null != ($view = $event->get('view')) && $view instanceof TemplateView) {
            $context = $view->getVariables();
            if (array_key_exists('currentOrder', $context)) {
                $this->order = $context['currentOrder'];
            }
        }
    }

    /**
     * Create conversion tracker code.
     *
     * @return string The conversion tracker code.
     */
    protected function getConversionTracker() {
        if (!$this->get('conversionTracker') || null == $this->order) {
            return '';
        }
        if (null == $this->order) {
            return '';
        }
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
}

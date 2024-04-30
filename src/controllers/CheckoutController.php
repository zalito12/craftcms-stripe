<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\stripe\controllers;

use Craft;
use craft\stripe\Plugin;
use craft\web\Controller;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * The CheckoutController handles creating a Stripe checkout session
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 */
class CheckoutController extends Controller
{
    /**
     * @return Response
     * @throws \Throwable
     * @throws InvalidConfigException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionCheckout(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $currentUser = Craft::$app->getUser()->getIdentity();

        // process purchasables
        $purchasables = $request->getRequiredBodyParam('purchasables');
        $lineItems = collect($purchasables)->filter(fn($item) => $item['quantity'] > 0)->all();

        if (empty($lineItems)) {
            return $this->asFailure(Craft::t('stripe', 'Please specify the quantity'));
        }

        $successUrl = $request->getValidatedBodyParam('successUrl', null);
        $cancelUrl = $request->getValidatedBodyParam('cancelUrl', null);
        $params = $request->getValidatedBodyParam('params', null);

        // start checkout session
        $url = Plugin::getInstance()->getCheckout()->getCheckoutUrl($lineItems, $currentUser, $successUrl, $cancelUrl, $params);

        return $this->redirect($url);
    }
}

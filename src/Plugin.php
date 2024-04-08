<?php

namespace craft\stripe;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\services\Elements;
use craft\services\Fields;
use craft\stripe\elements\Price;
use craft\stripe\elements\Product;
use craft\stripe\fieldlayoutelements\PricesField;
use craft\stripe\fields\Products as ProductsField;
use craft\stripe\models\Settings;
use craft\stripe\services\Api;
use craft\stripe\services\Prices;
use craft\stripe\services\Products;
use craft\web\UrlManager;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * Stripe plugin
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @author Pixel & Tonic <support@craftcms.com>
 * @copyright Pixel & Tonic
 * @license MIT
 * @property-read Products $products
 * @property-read Api $api
 */
class Plugin extends BasePlugin
{
    public const PC_PATH_PRODUCT_FIELD_LAYOUTS = 'stripe.productFieldLayout';
    public const PC_PATH_PRICE_FIELD_LAYOUTS = 'stripe.priceFieldLayout';

    /**
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public string $minVersionRequired = '5.0.0';

    /**
     * @var string stripe environment determined from the key
     * fall back to test environment to be safe
     */
    public string $stripeMode = 'test';

    /**
     * @var string Stripe Dashboard URL
     */
    public string $dashboardUrl = 'https://dashboard.stripe.com';

    public static function config(): array
    {
        return [
            'components' => [
                'api' => ['class' => Api::class],
                'prices' => ['class' => Prices::class],
                'products' => ['class' => Products::class],
                //'store' => ['class' => Store::class],
            ],
        ];
    }

    public function init()
    {
        parent::init();

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            $request = Craft::$app->getRequest();
            
            $this->_registerElementTypes();
//            $this->_registerUtilityTypes();
            $this->_registerFieldTypes();
            $this->_registerFieldLayoutElements();
//            $this->_registerVariables();
//            $this->_registerResaveCommands();

            if (!$request->getIsConsoleRequest()) {
                if ($request->getIsCpRequest()) {
                    $this->_registerCpRoutes();
                } /*else {
                    $this->_registerSiteRoutes();
                }*/
            }
//
            $projectConfigService = Craft::$app->getProjectConfig();
            $productsService = $this->getProducts();
            $pricesService = $this->getPrices();

            $projectConfigService->onAdd(self::PC_PATH_PRODUCT_FIELD_LAYOUTS, [$productsService, 'handleChangedFieldLayout'])
                ->onUpdate(self::PC_PATH_PRODUCT_FIELD_LAYOUTS, [$productsService, 'handleChangedFieldLayout'])
                ->onRemove(self::PC_PATH_PRODUCT_FIELD_LAYOUTS, [$productsService, 'handleDeletedFieldLayout']);

            $projectConfigService->onAdd(self::PC_PATH_PRICE_FIELD_LAYOUTS, [$pricesService, 'handleChangedFieldLayout'])
                ->onUpdate(self::PC_PATH_PRICE_FIELD_LAYOUTS, [$pricesService, 'handleChangedFieldLayout'])
                ->onRemove(self::PC_PATH_PRICE_FIELD_LAYOUTS, [$pricesService, 'handleDeletedFieldLayout']);

//            // Globally register stripe webhooks registry event handlers
//            Registry::addHandler(Topics::PRODUCTS_CREATE, new ProductHandler());
//            Registry::addHandler(Topics::PRODUCTS_DELETE, new ProductHandler());
//            Registry::addHandler(Topics::PRODUCTS_UPDATE, new ProductHandler());
//            Registry::addHandler(Topics::INVENTORY_LEVELS_UPDATE, new ProductHandler());

            // get stripe environment from the secret key
            $this->stripeMode = $this->_getStripeMode();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('stripe/settings'));
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('stripe/settings/index.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * Returns the API service
     *
     * @return Api The API service
     * @throws InvalidConfigException
     */
    public function getApi(): Api
    {
        return $this->get('api');
    }

    /**
     * Returns the Prices service
     *
     * @return Prices The Prices service
     * @throws InvalidConfigException
     */
    public function getPrices(): Prices
    {
        return $this->get('prices');
    }

    /**
     * Returns the Products service
     *
     * @return Products The Products service
     * @throws InvalidConfigException
     */
    public function getProducts(): Products
    {
        return $this->get('products');
    }

//    /**
//     * Returns the Store service
//     *
//     * @return Store The Store service
//     * @throws InvalidConfigException
//     * @since 3.0
//     */
//    public function getStore(): Store
//    {
//        return $this->get('store');
//    }

//    /**
//     * Registers the utilities.
//     *
//     * @since 3.0
//     */
//    private function _registerUtilityTypes(): void
//    {
//        Event::on(
//            Utilities::class,
//            Utilities::EVENT_REGISTER_UTILITIES,
//            function(RegisterComponentTypesEvent $event) {
//                $event->types[] = Sync::class;
//            }
//        );
//    }

    /**
     * Register the element types supplied by Stripe
     *
     * @since 3.0
     */
    private function _registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = Product::class;
            $event->types[] = Price::class;
        });
    }

    /**
     * Register Field Types
     *
     * @return void
     */
    private function _registerFieldTypes(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, static function(RegisterComponentTypesEvent $event) {
            $event->types[] = ProductsField::class;
        });
    }

    /**
     * Register field layout elements
     *
     * @return void
     */
    private function _registerFieldLayoutElements(): void {
        Event::on(FieldLayout::class, FieldLayout::EVENT_DEFINE_NATIVE_FIELDS, function(DefineFieldLayoutFieldsEvent $event) {
            /** @var FieldLayout $fieldLayout */
            $fieldLayout = $event->sender;

            switch ($fieldLayout->type) {
                case Product::class:
                    $event->fields[] = PricesField::class;
                    break;
            }
        });
    }

//    /**
//     * Register Stripe twig variables to the main craft variable
//     *
//     * @since 3.0
//     */
//    private function _registerVariables(): void
//    {
//        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event) {
//            $variable = $event->sender;
//            $variable->attachBehavior('stripe', CraftVariableBehavior::class);
//        });
//    }

//    public function _registerResaveCommands(): void
//    {
//        Event::on(ResaveController::class, Controller::EVENT_DEFINE_ACTIONS, static function(DefineConsoleActionsEvent $e) {
//            $e->actions['stripe-products'] = [
//                'action' => function(): int {
//                    /** @var ResaveController $controller */
//                    $controller = Craft::$app->controller;
//                    return $controller->resaveElements(Product::class);
//                },
//                'options' => [],
//                'helpSummary' => 'Re-saves Stripe products.',
//            ];
//        });
//    }

    /**
     * Register the CP routes
     */
    private function _registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            //$session = Plugin::getInstance()->getApi()->getSession();
            //$session = false;
            $event->rules['stripe'] = ['template' => 'stripe/_index', 'variables' => [/*'hasSession' => (bool)$session*/]];
            $event->rules['stripe/products'] = 'stripe/products/product-index';
            $event->rules['stripe/products/<elementId:\\d+>'] = 'elements/edit';
            $event->rules['stripe/settings'] = 'stripe/settings';
            $event->rules['stripe/settings/products'] = 'stripe/settings/products';
            $event->rules['stripe/settings/prices'] = 'stripe/settings/prices';

            $event->rules['stripe/prices/<elementId:\\d+>'] = 'elements/edit';

//            $event->rules['stripe/sync-products'] = 'stripe/products/sync';
//            $event->rules['stripe/webhooks'] = 'stripe/webhooks/edit';
        });
    }

//    /**
//     * Registers the Site routes.
//     *
//     * @since 3.0
//     */
//    private function _registerSiteRoutes(): void
//    {
//        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
//            $event->rules['stripe/webhook/handle'] = 'stripe/webhook/handle';
//        });
//    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $ret = parent::getCpNavItem();
        $ret['label'] = Craft::t('stripe', 'Stripe');


        $ret['subnav']['products'] = [
            'label' => Craft::t('stripe', 'Products'),
            'url' => 'stripe/products',
        ];


        if (Craft::$app->getUser()->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $ret['subnav']['settings'] = [
                'label' => Craft::t('stripe', 'Settings'),
                'url' => 'stripe/settings',
            ];
        }


//        if (Craft::$app->getUser()->getIsAdmin()) {
//            $ret['subnav']['webhooks'] = [
//                'label' => Craft::t('stripe', 'Webhooks'),
//                'url' => 'stripe/webhooks',
//            ];
//        }


        return $ret;
    }

    /**
     * Get Stripe mode from the used secret key prefix.
     *
     * @return string
     */
    private function _getStripeMode(): string
    {
        $settings = $this->getSettings();
        $secretKey = App::parseEnv($settings->secretKey);

        if (!str_starts_with($secretKey, 'sk_test_')) {
            return 'live';
        }

        return 'test';
    }
}

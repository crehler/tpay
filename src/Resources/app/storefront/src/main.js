const PluginManager = window.PluginManager;

PluginManager.register(
    'TpayCardForm',
    () => import('./cr-tpay-card-form/tpay-card-form.plugin'),
    '[data-tpay-card-form]'
);

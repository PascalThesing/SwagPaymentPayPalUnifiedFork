(function($) {
    var SwagPaymentPaypalCreateOrderFunction = function(createOrderUrl, sourcePlugin) {
        this.createOrderUrl = createOrderUrl;
        this.sourcePlugin = sourcePlugin;
    };

    SwagPaymentPaypalCreateOrderFunction.prototype.storageFieldPluginCssSelector = '*[data-storage-field="true"]';
    SwagPaymentPaypalCreateOrderFunction.prototype.storageFieldPluginSelector = 'plugin_swStorageField';

    SwagPaymentPaypalCreateOrderFunction.prototype.customerCommentFieldSelector = '.user-comment--field';
    SwagPaymentPaypalCreateOrderFunction.prototype.fallbackParamerterName = 'sComment';

    SwagPaymentPaypalCreateOrderFunction.prototype.registerNewsletterFieldSelector = '#sNewsletter';
    SwagPaymentPaypalCreateOrderFunction.prototype.registerNewsletterParamerterName = 'sNewsletter';

    SwagPaymentPaypalCreateOrderFunction.prototype.createOrder = function() {
        var me = this;

        return $.ajax({
            method: 'post',
            url: this.createOrderUrl,
            data: this.createExtraData()
        }).then(function(response) {
            me.sourcePlugin.opts.basketId = response.basketId;

            return response.token;
        }, function(response) {
            me.latestResponse = response;
        }).promise();
    };

    SwagPaymentPaypalCreateOrderFunction.prototype.onApiError = function() {
        if (!this.latestResponse) {
            this._redirectToErrorPageIfAvailable();
            return;
        }

        if (this.latestResponse.responseText !== '') {
            var jsonResponse = JSON.parse(this.latestResponse.responseText);

            delete this.latestResponse;

            if (jsonResponse.redirectTo) {
                window.location.replace(jsonResponse.redirectTo);
                return;
            }
        }

        this._redirectToErrorPageIfAvailable();
    };

    SwagPaymentPaypalCreateOrderFunction.prototype.createExtraData = function() {
        var $extraDataPlugin = $(this.storageFieldPluginCssSelector).data(this.storageFieldPluginSelector),
            result = this.checkNewsletterCheckbox(),
            $customerCommentField;

        if ($extraDataPlugin && $extraDataPlugin.$el.length && $extraDataPlugin.storage.getItem($extraDataPlugin.getStorageKey())) {
            result[$extraDataPlugin.opts.storageKeyName] = $extraDataPlugin.storage.getItem($extraDataPlugin.getStorageKey());

            // Removes the value from the storage manager
            $extraDataPlugin.onFormSubmit();

            return result;
        }

        $customerCommentField = $(this.customerCommentFieldSelector);
        if ($customerCommentField.length) {
            result[this.fallbackParamerterName] = $customerCommentField.val();
        }

        return result;
    };

    SwagPaymentPaypalCreateOrderFunction.prototype.checkNewsletterCheckbox = function() {
        var $registerNewsletterField = $(this.registerNewsletterFieldSelector),
            result = {};

        if ($registerNewsletterField.length && $registerNewsletterField.is(':checked')) {
            result[this.registerNewsletterParamerterName] = true;
        }

        return result;
    };

    SwagPaymentPaypalCreateOrderFunction.prototype._redirectToErrorPageIfAvailable = function() {
        if (!this.sourcePlugin.opts.paypalErrorPage) {
            return;
        }

        window.location.replace(this.sourcePlugin.opts.paypalErrorPage);
    };

    $.createSwagPaymentPaypalCreateOrderFunction = function(createOrderUrl, sourcePlugin) {
        return new SwagPaymentPaypalCreateOrderFunction(createOrderUrl, sourcePlugin);
    };
})(jQuery);

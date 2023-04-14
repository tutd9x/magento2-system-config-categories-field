define([
    'Magento_Ui/js/form/element/ui-select',
    'jquery'
], function (Select, $) {
    'use strict';

    return Select.extend({
        defaults: {
            elementSelectorId: ''
        },
        /**
         * Remove element from selected array
         */
        removeSelected: function (value, data, event) {
            this._super();
            this._getElementSelect().val(this.value()).change();
        },
        /**
         * Toggle activity list element
         *
         * @param {Object} data - selected option data
         * @returns {Object} Chainable
         */
        toggleOptionSelected: function (data) {
            this._super();
            this._getElementSelect().val(this.value()).change();
            return this;
        },
        _getElementSelect: function () {
            return $(this.elementSelectorId);
        },
    });
});

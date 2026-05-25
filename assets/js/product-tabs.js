/* global wcProductTabs, jQuery */
(function ($) {
    'use strict';

    /* =======================================================================
     * ProductTabs — single product page UI
     * ======================================================================= */

    var ProductTabs = {
        $container: null,
        data: null,
        tabsPriority: ['flakony', 'rozpyv', 'zalyszky'],

        // Current selection state
        state: {
            tab: null,   // 'flakony' | 'zalyszky' | 'rozpyv'
            variant: null,   // object from tabs.flakony/zalyszky.variants[]
            size: null,   // number (ml) for rozpyv
            atomizer: null,   // atomizer object for rozpyv

            // Remember last choices so returning to tab/size restores previous selection.
            remembered: {
                flakony: { variantIndex: null },
                zalyszky: { variantIndex: null },
                rozpyv: {
                    size: null,
                    atomizerId: null,
                },
            },
        },

        /* ---- Init ---- */
        init: function () {
            if (!window.wcProductTabs || !window.wcProductTabs.product_tabs) return;

            this.$container = $('#wc-product-tabs');
            if (!this.$container.length) return;

            this.data = window.wcProductTabs.product_tabs;
            this.tabsPriority = this.getTabsPriority();
            this.render();
            this.bindEvents();
            this.autoSelect();
        },

        getTabsPriority: function () {
            var payloadPriority = window.wcProductTabs.tabs_priority;
            var allowed = ['flakony', 'rozpyv', 'zalyszky'];
            var normalized = [];

            if (Array.isArray(payloadPriority)) {
                payloadPriority.forEach(function (item) {
                    var key = String(item || '');
                    if (allowed.indexOf(key) !== -1 && normalized.indexOf(key) === -1) {
                        normalized.push(key);
                    }
                });
            }

            allowed.forEach(function (key) {
                if (normalized.indexOf(key) === -1) {
                    normalized.push(key);
                }
            });

            return normalized.slice(0, 3);
        },

        getOrderedTabKeys: function () {
            var tabs = this.data.tabs || {};
            var ordered = [];

            this.tabsPriority.forEach(function (key) {
                if (tabs[key]) {
                    ordered.push(key);
                }
            });

            Object.keys(tabs).forEach(function (key) {
                if (ordered.indexOf(key) === -1) {
                    ordered.push(key);
                }
            });

            return ordered;
        },

        /* ---- Render ---- */
        render: function () {
            var tabs = this.data.tabs;
            var tabKeys = this.getOrderedTabKeys();
            var html = '';

            // Tab navigation
            html += '<div class="wct-nav">';
            tabKeys.forEach(function (key) {
                html += '<button type="button" class="wct-nav-btn" data-tab="' + esc(key) + '">' +
                    esc(tabs[key].label) + '</button>';
            });
            html += '</div>';

            // Tab panels
            html += '<div class="wct-panels">';
            var self = this;
            tabKeys.forEach(function (key) {
                html += '<div class="wct-panel" data-tab="' + esc(key) + '">';
                if (key === 'flakony' || key === 'zalyszky') {
                    html += self.renderVariants(tabs[key].variants, key);
                } else if (key === 'rozpyv') {
                    html += self.renderRozpyv(tabs[key]);
                }
                html += '</div>';
            });
            html += '</div>';

            // Cart wrap
            html += '<div class="wct-cart-wrap">';
            html += '<div class="wct-summary"></div>';
            html += '<form class="cart wct-form" method="post">';
            html += '<input type="hidden" name="add-to-cart" value="' + esc(this.data.product_id) + '">';
            html += '<input type="hidden" name="wc_product_tabs_nonce" value="' + esc(window.wcProductTabs.add_to_cart_nonce || '') + '">';
            html += '<input type="hidden" name="wc_product_tab_data" id="wct_tab_data" value="">';
            html += '<div class="wct-form-row">';
            html += '<div class="wct-qty">';
            html += '<label class="wct-qty-label">Кількість</label>';
            html += '<div class="wct-qty-controls">';
            html += '<button type="button" class="wct-qty-btn wct-qty-minus" aria-label="Зменшити">−</button>';
            html += '<input type="number" class="wct-qty-input" name="quantity" value="1" min="1" max="99">';
            html += '<button type="button" class="wct-qty-btn wct-qty-plus" aria-label="Збільшити">+</button>';
            html += '</div>';
            html += '</div>';
            html += '<button type="submit" class="single_add_to_cart_button button alt wct-submit" disabled>';
            html += esc(i18n('add_to_cart'));
            html += '</button>';
            html += '</div>';
            html += '</form>';
            html += '</div>';

            this.$container.html(html);
        },

        renderVariants: function (variants, tabKey) {
            var currency = window.wcProductTabs.currency || '';
            var html = '<div class="wct-variants">';

            variants.forEach(function (v, idx) {
                var currentPrice = Number(v.price_value || 0);
                var oldPrice = parseFloat(v.old_price || 0);
                var hasValidPrice = currentPrice > 0;
                var outOfStock = !v.available || !hasValidPrice;
                var priceHtml = hasValidPrice
                    ? '<span class="wct-price">' + esc(formatPrice(currentPrice, currency)) + '</span>'
                    : '';
                var oldPriceHtml = (hasValidPrice && oldPrice > currentPrice)
                    ? '<span class="wct-old-price"><s>' + esc(formatPrice(oldPrice, currency)) + '</s></span>'
                    : '';
                var keyHtml = '<span class="wct-variant-key">' + esc(v.key || v.index) + '</span>';
                var stockBadge = outOfStock
                    ? '<span class="wct-out-of-stock-badge">Немає в наявності</span>'
                    : '';

                html += '<div class="wct-variant' + (outOfStock ? ' out-of-stock' : '') + '" data-tab="' + esc(tabKey) +
                    '" data-variant-index="' + idx + '">';
                html += keyHtml;
                html += '<div class="wct-variant-prices">' + priceHtml + oldPriceHtml + '</div>';
                html += stockBadge;
                html += '</div>';
            });

            html += '</div>';
            html += '<div class="wct-tab-desc"></div>';
            return html;
        },

        renderRozpyv: function (tabData) {
            var html = '<div class="wct-rozpyv">';

            if (!tabData || !tabData.base || !tabData.base.available) {
                html += '<div class="wct-out-of-stock-badge">' + esc(i18n('out_of_stock')) + '</div>';
                html += '<div class="wct-tab-desc"></div>';
                html += '</div>';
                return html;
            }

            // Size selector
            html += '<p class="wct-section-label">Обʼєм</p>';
            html += '<div class="wct-sizes">';
            tabData.sizes.forEach(function (size) {
                var sizeOption = tabData.size_options && tabData.size_options[String(size)];
                if (!sizeOption || !sizeOption.available) {
                    return;
                }

                html += '<button type="button" class="wct-size-btn" data-size="' + size + '">' +
                    size + ' мл</button>';
            });
            html += '</div>';

            // Atomizer selector (shown after size chosen)
            html += '<div class="wct-atomizers-wrap" style="display:none">';
            html += '<p class="wct-section-label">Атомайзер</p>';
            html += '<div class="wct-atomizers">';
            tabData.atomizers.forEach(function (a, idx) {
                var imgHtml = a.image
                    ? '<img src="' + esc(window.wcProductTabs.atomizers_url + a.image) +
                    '" alt="' + esc(a.title) + '" class="wct-atomizer-img">'
                    : '';
                html += '<div class="wct-atomizer" data-atomizer-index="' + idx + '">';
                html += imgHtml;
                html += '<span class="wct-atomizer-title">' + esc(a.title) + '</span>';
                html += '<span class="wct-atomizer-price"></span>';
                html += '</div>';
            });
            html += '</div>';
            html += '</div>';

            html += '<div class="wct-tab-desc"></div>';
            html += '</div>';
            return html;
        },

        /* ---- Events ---- */
        bindEvents: function () {
            var self = this;

            // Tab switch
            this.$container.on('click', '.wct-nav-btn', function () {
                self.switchTab($(this).data('tab'));
            });

            // Variant selection (Flakony / Zalyszky) — out-of-stock variants are not clickable
            this.$container.on('click', '.wct-variant:not(.out-of-stock)', function () {
                var $panel = $(this).closest('.wct-panel');
                var tabKey = $panel.data('tab');
                var vIndex = $(this).data('variant-index');
                var variant = self.data.tabs[tabKey].variants[vIndex];

                $panel.find('.wct-variant').removeClass('selected');
                $(this).addClass('selected');

                // Show variant description below the options list
                $panel.find('.wct-tab-desc').text(variant.desc || '');

                self.state.tab = tabKey;
                self.state.variant = variant;
                self.state.size = null;
                self.state.atomizer = null;
                if (self.state.remembered[tabKey]) {
                    self.state.remembered[tabKey].variantIndex = vIndex;
                }

                self.updateSummary();
            });

            // Size selection (Rozpyv)
            this.$container.on('click', '.wct-size-btn', function () {
                var $panel = $(this).closest('.wct-panel');
                var size = parseInt($(this).data('size'), 10) || 0;
                var base = self.data.tabs.rozpyv.base || {};

                $panel.find('.wct-size-btn').removeClass('active');
                $(this).addClass('active');

                self.state.tab = 'rozpyv';
                self.state.size = size;

                self.state.remembered.rozpyv.size = size;

                // Show base rozpyv description once size is selected.
                $panel.find('.wct-tab-desc').text(base.desc || '');

                var $wrap = $panel.find('.wct-atomizers-wrap');
                $wrap.show();

                self.filterAtomizersForSize($panel, size);

                // Try to keep previously selected atomizer when size changes.
                var preferredAtomizerId = self.state.remembered.rozpyv.atomizerId;
                self.selectAtomizerForSize($panel, size, preferredAtomizerId);

                self.updateSummary();
            });

            // Atomizer selection (Rozpyv)
            this.$container.on('click', '.wct-atomizer:not(.hidden)', function () {
                var $panel = $(this).closest('.wct-panel');
                var idx = $(this).data('atomizer-index');

                self.setAtomizerSelection($panel, idx);
                self.updateSummary();
            });

            // Quantity controls
            this.$container.on('click', '.wct-qty-minus', function () {
                var $input = $(this).siblings('.wct-qty-input');
                $input.val(Math.max(1, (parseInt($input.val(), 10) || 1) - 1));
            });
            this.$container.on('click', '.wct-qty-plus', function () {
                var $input = $(this).siblings('.wct-qty-input');
                $input.val(Math.min(99, (parseInt($input.val(), 10) || 1) + 1));
            });

            // Form submit — validate selection exists
            this.$container.on('submit', '.wct-form', function (e) {
                self.updateSummary();

                if (!$('#wct_tab_data').val()) {
                    e.preventDefault();
                    var msg = (self.state.tab === 'rozpyv' && self.state.size && !self.state.atomizer)
                        ? i18n('select_atomizer')
                        : i18n('select_option');
                    /* eslint-disable no-alert */
                    alert(msg);
                }
            });
        },

        switchTab: function (tabKey) {
            this.$container.find('.wct-nav-btn').removeClass('active');
            this.$container.find('.wct-nav-btn[data-tab="' + tabKey + '"]').addClass('active');

            this.$container.find('.wct-panel').removeClass('active');
            this.$container.find('.wct-panel[data-tab="' + tabKey + '"]').addClass('active');

            this.state.tab = tabKey;
            this.state.variant = null;
            this.state.size = null;
            this.state.atomizer = null;

            // Drop previous valid payload when moving between tabs.
            this.updateSummary();

            this.restoreOrSelectOption(tabKey);
        },

        restoreOrSelectOption: function (tabKey) {
            var $panel = this.$container.find('.wct-panel[data-tab="' + tabKey + '"]');

            if (tabKey === 'flakony' || tabKey === 'zalyszky') {
                var rememberedVariantIndex = this.state.remembered[tabKey] ? this.state.remembered[tabKey].variantIndex : null;
                var $rememberedVariant = rememberedVariantIndex !== null
                    ? $panel.find('.wct-variant[data-variant-index="' + rememberedVariantIndex + '"]:not(.out-of-stock)')
                    : $();

                if ($rememberedVariant.length) {
                    $rememberedVariant.first().trigger('click');
                    return;
                }

                $panel.find('.wct-variant:not(.out-of-stock):first').trigger('click');
            } else if (tabKey === 'rozpyv') {
                var rememberedSize = this.state.remembered.rozpyv.size;
                var rozpyvTabData = this.data.tabs.rozpyv || {};
                var $sizeBtn = $();

                if (!rozpyvTabData.base || !rozpyvTabData.base.available) {
                    this.state.size = null;
                    this.state.atomizer = null;
                    this.updateSummary();
                    return;
                }

                if (rememberedSize !== null && this.isRozpyvSizeAvailable(rozpyvTabData, rememberedSize)) {
                    $sizeBtn = $panel.find('.wct-size-btn[data-size="' + rememberedSize + '"]');
                }

                if (!$sizeBtn.length) {
                    var firstAvailableSize = this.findFirstAvailableRozpyvSize(rozpyvTabData);
                    if (firstAvailableSize !== null) {
                        $sizeBtn = $panel.find('.wct-size-btn[data-size="' + firstAvailableSize + '"]');
                    }
                }

                if ($sizeBtn.length) {
                    $sizeBtn.first().trigger('click');
                    return;
                }

                this.state.size = null;
                this.state.atomizer = null;
                this.updateSummary();
            }
        },

        autoSelect: function () {
            var firstAvailableKey = this.findFirstAvailableTabKey();
            if (firstAvailableKey) {
                this.switchTab(firstAvailableKey);
                return;
            }

            var orderedKeys = this.getOrderedTabKeys();
            var firstKey = orderedKeys.length ? orderedKeys[0] : null;
            if (firstKey) {
                this.switchTab(firstKey);
            }
        },

        findFirstAvailableTabKey: function () {
            var tabs = this.data.tabs || {};
            var preferredOrder = this.tabsPriority;

            for (var i = 0; i < preferredOrder.length; i += 1) {
                var tabKey = preferredOrder[i];
                if (!tabs[tabKey]) {
                    continue;
                }

                if ((tabKey === 'flakony' || tabKey === 'zalyszky') && this.hasAvailableVariant(tabs[tabKey])) {
                    return tabKey;
                }

                if (tabKey === 'rozpyv' && this.findFirstAvailableRozpyvSize(tabs[tabKey]) !== null) {
                    return tabKey;
                }
            }

            return null;
        },

        hasAvailableVariant: function (tabData) {
            if (!tabData || !Array.isArray(tabData.variants)) {
                return false;
            }

            for (var i = 0; i < tabData.variants.length; i += 1) {
                var variant = tabData.variants[i] || {};
                if (variant.available) {
                    return true;
                }
            }

            return false;
        },

        isRozpyvSizeAvailable: function (tabData, size) {
            var parsedSize = parseInt(size, 10) || 0;

            if (parsedSize <= 0) {
                return false;
            }

            return !!(tabData && tabData.size_options && tabData.size_options[String(parsedSize)] && tabData.size_options[String(parsedSize)].available);
        },

        findFirstAvailableRozpyvSize: function (tabData) {
            if (!tabData || !Array.isArray(tabData.sizes)) {
                return null;
            }

            for (var i = 0; i < tabData.sizes.length; i += 1) {
                var size = parseInt(tabData.sizes[i], 10) || 0;
                if (this.isRozpyvSizeAvailable(tabData, size)) {
                    return size;
                }
            }

            return null;
        },

        filterAtomizersForSize: function ($panel, size) {
            var atomizers = this.data.tabs.rozpyv.atomizers;
            var currency = window.wcProductTabs.currency || '';

            $panel.find('.wct-atomizer').each(function () {
                var idx = $(this).data('atomizer-index');
                var atomizer = atomizers[idx] || {};
                var available = isAtomizerAvailableForSize(atomizer, size);

                if (available) {
                    var atomizerPrice = getAtomizerPriceForSize(atomizer, size);
                    var text = atomizerPrice !== null ? formatPrice(atomizerPrice, currency) : '';
                    $(this).find('.wct-atomizer-price').text(text);
                    $(this).removeClass('hidden active');
                } else {
                    $(this).addClass('hidden').removeClass('active');
                }
            });
        },

        selectAtomizerForSize: function ($panel, size, preferredAtomizerId) {
            var self = this;
            var selectedIndex = null;

            if (preferredAtomizerId) {
                $panel.find('.wct-atomizer:not(.hidden)').each(function () {
                    if (selectedIndex !== null) {
                        return;
                    }
                    var idx = $(this).data('atomizer-index');
                    var atomizer = self.data.tabs.rozpyv.atomizers[idx] || {};
                    if (atomizer.id === preferredAtomizerId) {
                        selectedIndex = idx;
                    }
                });
            }

            if (selectedIndex === null) {
                var $firstVisible = $panel.find('.wct-atomizer:not(.hidden):first');
                if ($firstVisible.length) {
                    selectedIndex = $firstVisible.data('atomizer-index');
                }
            }

            if (selectedIndex !== null && selectedIndex !== undefined) {
                this.setAtomizerSelection($panel, selectedIndex);
                return;
            }

            $panel.find('.wct-atomizer').removeClass('active');
            this.state.atomizer = null;
        },

        setAtomizerSelection: function ($panel, idx) {
            var atomizer = this.data.tabs.rozpyv.atomizers[idx] || null;

            $panel.find('.wct-atomizer').removeClass('active');

            if (!atomizer) {
                this.state.atomizer = null;
                return;
            }

            var $el = $panel.find('.wct-atomizer[data-atomizer-index="' + idx + '"]');
            if (!$el.length || $el.hasClass('hidden')) {
                this.state.atomizer = null;
                return;
            }

            $el.addClass('active');
            this.state.atomizer = atomizer;

            if (atomizer.id) {
                this.state.remembered.rozpyv.atomizerId = atomizer.id;
            }
        },

        /* ---- Summary & hidden field ---- */
        updateSummary: function () {
            var s = this.state;
            var cartData = null;
            var currency = window.wcProductTabs.currency || '';
            var summaryHtml = this.renderSummaryRow('', null, null);

            if ((s.tab === 'flakony' || s.tab === 'zalyszky') && s.variant) {
                var v = s.variant;
                var variantPrice = Number(v.price_value || 0);
                var variantIsAvailable = !!v.available && variantPrice > 0;

                if (variantIsAvailable) {
                    cartData = {
                        tab: s.tab,
                        key: v.key || '',
                        variant_index: v.index,
                        price: variantPrice,
                        desc: v.desc || '',
                    };
                    summaryHtml = this.renderSummaryRow(
                        v.key || 'Варіант',
                        formatPrice(cartData.price, currency),
                        ''
                    );
                } else {
                    cartData = null;
                    summaryHtml = this.renderSummaryRow(
                        v.key || 'Варіант',
                        '',
                        i18n('select_option')
                    );
                }

            } else if (s.tab === 'rozpyv') {
                var base = this.data.tabs.rozpyv.base;

                if (!base || !base.available) {
                    cartData = null;
                    summaryHtml = this.renderSummaryRow(
                        'Розпив',
                        '',
                        i18n('out_of_stock')
                    );
                    this.$container.find('.wct-atomizers-wrap').hide();
                } else if (s.size) {
                var basePrice = Number((base && base.price_per_ml) || 0) * s.size;
                var sizeIsAvailable = this.isRozpyvSizeAvailable(this.data.tabs.rozpyv, s.size);

                if (!sizeIsAvailable || basePrice <= 0) {
                    cartData = null;
                    summaryHtml = this.renderSummaryRow(
                        'Розпив ' + s.size + ' мл',
                        '',
                        i18n('select_option')
                    );
                } else {
                    cartData = {
                        tab: 'rozpyv',
                        key: base.key || '',
                        pos_id: base.pos_id || '',
                        size_ml: s.size,
                        price: basePrice,
                        desc: 'Розпив ' + s.size + ' мл',
                    };

                    if (s.atomizer) {
                        var atomizerOption = getRozpyvAtomizerOption(s.atomizer, s.size);
                        var atomizerIsAvailable = !!(atomizerOption && atomizerOption.available);

                        if (!atomizerIsAvailable) {
                            this.state.atomizer = null;
                            cartData = null;
                            summaryHtml = this.renderSummaryRow(
                                'Розпив ' + s.size + ' мл',
                                formatPrice(basePrice, currency),
                                i18n('select_atomizer')
                            );
                            this.$container.find('.wct-atomizer').removeClass('active');
                        } else {
                            var aPrice = Number(atomizerOption.atomizer_price || 0);
                            var finalPrice = Number(atomizerOption.total_price || 0);

                            if (aPrice < 0 || finalPrice <= 0) {
                                cartData = null;
                                summaryHtml = this.renderSummaryRow(
                                    'Розпив ' + s.size + ' мл',
                                    '',
                                    i18n('select_option')
                                );
                            } else {
                                cartData.atomizer_id = s.atomizer.id;
                                cartData.atomizer_title = s.atomizer.title;
                                cartData.atomizer_price = aPrice;
                                cartData.price = finalPrice;
                                cartData.desc = 'Розпив ' + s.size + ' мл — ' + s.atomizer.title;

                                summaryHtml = this.renderSummaryRow(
                                    cartData.desc,
                                    formatPrice(cartData.price, currency),
                                    ''
                                );

                                this.$container
                                    .find('.wct-panel[data-tab="rozpyv"] .wct-tab-desc')
                                    .text(base.desc || '');
                            }
                        }
                    } else {
                        // Size chosen but no atomizer yet — keep summary style consistent and block submit.
                        cartData = null;
                        summaryHtml = this.renderSummaryRow(
                            'Розпив ' + s.size + ' мл',
                            formatPrice(basePrice, currency),
                            i18n('select_atomizer')
                        );
                    }
                }
                } else {
                    cartData = null;
                    summaryHtml = this.renderSummaryRow(
                        'Розпив',
                        '',
                        i18n('select_option')
                    );
                }
            }

            this.$container.find('.wct-summary').html(summaryHtml);
            $('#wct_tab_data').val(cartData ? JSON.stringify(cartData) : '');
            this.$container.find('.wct-submit').prop('disabled', !cartData);
        },

        renderSummaryRow: function (label, priceText, subtext) {
            var html = '';
            if (label) {
                html += '<span class="wct-summary-label">' + esc(label) + '</span>';
            }
            if (priceText) {
                html += '<span class="wct-summary-price">' + esc(priceText) + '</span>';
            }
            if (subtext) {
                html += '<span class="wct-summary-subtext">' + esc(subtext) + '</span>';
            }
            return html;
        },
    };

    /* =======================================================================
     * Helpers
     * ======================================================================= */

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function i18n(key) {
        return (window.wcProductTabs.i18n && window.wcProductTabs.i18n[key]) || key;
    }

    function formatPrice(amount, currency) {
        return currency + ' ' + Number(amount || 0).toFixed(2);
    }

    function getAtomizerPriceForSize(atomizer, size) {
        var option = getRozpyvAtomizerOption(atomizer, size);
        var price = option ? Number(option.atomizer_price) : null;

        if (price === null || Number.isNaN(price)) {
            return null;
        }

        return price;
    }

    function isAtomizerAvailableForSize(atomizer, size) {
        var option = getRozpyvAtomizerOption(atomizer, size);
        return !!(option && option.available);
    }

    function getRozpyvAtomizerOption(atomizer, size) {
        var sizeKey = String(parseInt(size, 10) || 0);

        if (!atomizer || !atomizer.options) {
            return null;
        }

        return atomizer.options[sizeKey] || null;
    }

    /* =======================================================================
     * Boot
     * ======================================================================= */

    $(document).ready(function () {
        ProductTabs.init();
    });

}(jQuery));

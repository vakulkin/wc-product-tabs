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
        notifyTarget: null,

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
            var allowed = this.tabsPriority;
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

            // Notify panel — rendered once, repositioned dynamically (floating).
            html += this.renderNotifyForm(false);

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
            var baseAvailable = tabData && tabData.base && tabData.base.available;

            var anySizeAvailable = false;
            if (baseAvailable && tabData && tabData.sizes) {
                for (var i = 0; i < tabData.sizes.length; i++) {
                    var s = String(tabData.sizes[i]);
                    var opt = tabData.size_options && tabData.size_options[s];
                    if (opt && opt.available) {
                        anySizeAvailable = true;
                        break;
                    }
                }
            }

            if (!baseAvailable || !anySizeAvailable) {
                // Globally OOS: show inline notify form; hide sizes and atomizers.
                var descHtml = esc(i18n('notify_desc_global'));
                html += this.renderNotifyForm(true, descHtml);
                html += '<div class="wct-tab-desc"></div>';
                html += '</div>';
                return html;
            }

            // Size selector — render only available sizes
            html += '<p class="wct-section-label">\u041e\u0431\'\u0454\u043c</p>';
            html += '<div class="wct-sizes">';
            if (tabData && tabData.sizes) {
                tabData.sizes.forEach(function (size) {
                    var sizeOption = tabData.size_options && tabData.size_options[String(size)];
                    var available = !!(sizeOption && sizeOption.available);
                    
                    if (available) {
                        html += '<button type="button" class="wct-size-btn" data-size="' + size + '">' +
                            size + ' \u043c\u043b</button>';
                    }
                });
            }
            html += '</div>';

            // Atomizer selector (shown after size chosen)
            html += '<div class="wct-atomizers-wrap" style="display:none">';
            html += '<p class="wct-section-label">\u0410\u0442\u043e\u043c\u0430\u0439\u0437\u0435\u0440</p>';
            html += '<div class="wct-atomizers">';
            if (tabData && tabData.atomizers) {
                tabData.atomizers.forEach(function (a, idx) {
                    var initialImage = resolveAtomizerImage(a.image);
                    var imgHtml = initialImage
                        ? '<img src="' + esc(initialImage) +
                        '" alt="' + esc(a.title) + '" class="wct-atomizer-img">'
                        : '';
                    html += '<div class="wct-atomizer" data-atomizer-index="' + idx + '">';
                    html += imgHtml;
                    html += '<span class="wct-atomizer-title">' + esc(a.title) + '</span>';
                    html += '<span class="wct-atomizer-price"></span>';
                    html += '</div>';
                });
            }
            html += '</div>';
            html += '</div>';

            html += '<div class="wct-tab-desc"></div>';
            html += '</div>';
            return html;
        },

        renderNotifyForm: function (isInline, customDesc) {
            var html = '';
            var cls = isInline ? 'wct-notify-panel wct-notify-inline' : 'wct-notify-panel wct-notify-floating';

            html += '<div class="' + cls + '">';
            html += '<div class="wct-notify-inner">';
            html += '<div class="wct-notify-header">';
            html += '<span class="wct-notify-title">' + esc(i18n('notify_title')) + '</span>';
            html += '</div>';

            var descHtml = customDesc
                ? customDesc
                : esc(i18n('notify_desc')) + ' <strong class="wct-notify-label-text"></strong>.';

            html += '<p class="wct-notify-desc">' + descHtml + '</p>';
            html += '<div class="wct-notify-form-row">';
            html += '<input type="tel" class="wct-notify-phone" placeholder="' + esc(i18n('notify_placeholder')) + '" autocomplete="tel" maxlength="16">';
            html += '<button type="button" class="wct-notify-submit">' + esc(i18n('notify_submit')) + '</button>';
            html += '</div>';
            html += '<div class="wct-notify-message"></div>';
            html += '</div>';
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

            // Variant selection — all variants clickable; OOS opens notify panel.
            this.$container.on('click', '.wct-variant', function () {
                var $el = $(this);
                var $panel = $el.closest('.wct-panel');
                var tabKey = $panel.data('tab');
                var vIndex = $el.data('variant-index');
                self.selectVariant(tabKey, vIndex, true);
            });

            // Size selection — only available sizes are rendered.
            this.$container.on('click', '.wct-size-btn', function () {
                var $panel = $(this).closest('.wct-panel');
                var size = parseInt($(this).data('size'), 10) || 0;
                self.selectRozpyvSize($panel, size);
            });

            // Atomizer selection
            this.$container.on('click', '.wct-atomizer', function () {
                var $el = $(this);
                var $panel = $el.closest('.wct-panel');
                var idx = $el.data('atomizer-index');

                self.closeNotifyPanel();
                self.setAtomizerSelection($panel, idx);
                self.updateSummary();
            });

            // Notify panel — close
            this.$container.on('click', '.wct-notify-close', function () {
                self.closeNotifyPanel();
            });

            // Notify panel — submit button (works for floating and inline panels)
            this.$container.on('click', '.wct-notify-submit', function () {
                self.submitNotify($(this).closest('.wct-notify-panel'));
            });

            // Notify panel — submit on Enter
            this.$container.on('keydown', '.wct-notify-phone', function (e) {
                if (e.which === 13) {
                    e.preventDefault();
                    self.submitNotify($(this).closest('.wct-notify-panel'));
                }
            });

            // Notify panel — simple mask for phone (allows optional + and digits)
            this.$container.on('input', '.wct-notify-phone', function () {
                var val = $(this).val();
                
                // Remove everything except digits and plus
                var cleaned = val.replace(/[^\d+]/g, '');
                
                // Ensure + only appears at the very beginning
                if (cleaned.indexOf('+') > 0) {
                    cleaned = cleaned.replace(/\+/g, function(match, offset) {
                        return offset === 0 ? '+' : '';
                    });
                }
                
                if (val !== cleaned) {
                    $(this).val(cleaned);
                }
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

            // Close any open notify panel when switching tabs.
            this.closeNotifyPanel();

            // Drop previous valid payload when moving between tabs.
            this.updateSummary();

            this.restoreOrSelectOption(tabKey);
        },

        restoreOrSelectOption: function (tabKey) {
            var self = this;
            var $panel = this.$container.find('.wct-panel[data-tab="' + tabKey + '"]');

            if (tabKey === 'flakony' || tabKey === 'zalyszky') {
                var rememberedVariantIndex = this.state.remembered[tabKey]
                    ? this.state.remembered[tabKey].variantIndex
                    : null;

                // 1. Try remembered in-stock variant.
                if (rememberedVariantIndex !== null) {
                    var $rem = $panel.find(
                        '.wct-variant[data-variant-index="' + rememberedVariantIndex + '"]:not(.out-of-stock)'
                    );
                    if ($rem.length) {
                        self.selectVariant(tabKey, $rem.first().data('variant-index'), false);
                        return;
                    }
                }

                // 2. First in-stock variant.
                var $inStock = $panel.find('.wct-variant:not(.out-of-stock):first');
                if ($inStock.length) {
                    self.selectVariant(tabKey, $inStock.data('variant-index'), false);
                    return;
                }

                // 3. Fallback: first variant even if OOS — notify panel opens automatically.
                var $first = $panel.find('.wct-variant:first');
                if ($first.length) {
                    self.selectVariant(tabKey, $first.data('variant-index'), true);
                }

            } else if (tabKey === 'rozpyv') {
                var rememberedSize = this.state.remembered.rozpyv.size;
                var rozpyvTabData = this.data.tabs.rozpyv || {};

                var globallyOOS = !rozpyvTabData.base || !rozpyvTabData.base.available;
                if (!globallyOOS) {
                    var anySizeAvailable = false;
                    var sizes = rozpyvTabData.sizes || [];
                    for (var i = 0; i < sizes.length; i++) {
                        if (this.isRozpyvSizeAvailable(rozpyvTabData, sizes[i])) {
                            anySizeAvailable = true;
                            break;
                        }
                    }
                    if (!anySizeAvailable) {
                        globallyOOS = true;
                    }
                }

                // Globally OOS: inline notify form is rendered; just set notifyTarget.
                if (globallyOOS) {
                    this.notifyTarget = {
                        product_id: this.data.product_id,
                        tab: 'rozpyv',
                    };
                    this.updateSummary();
                    setTimeout(function () {
                        $panel.find('.wct-notify-inline .wct-notify-phone').focus();
                    }, 80);
                    return;
                }

                // 1. Try remembered available size.
                if (rememberedSize !== null && this.isRozpyvSizeAvailable(rozpyvTabData, rememberedSize)) {
                    this.selectRozpyvSize($panel, rememberedSize);
                    return;
                }

                // 2. First available size.
                var firstAvailableSize = this.findFirstAvailableRozpyvSize(rozpyvTabData);
                if (firstAvailableSize !== null) {
                    this.selectRozpyvSize($panel, firstAvailableSize);
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
                    var atomizerImage = getAtomizerImageForSize(atomizer, size);

                    $(this).find('.wct-atomizer-price').text(text);

                    if (atomizerImage) {
                        var $img = $(this).find('.wct-atomizer-img');
                        if ($img.length) {
                            $img.attr('src', atomizerImage);
                        } else {
                            $(this).prepend('<img src="' + esc(atomizerImage) + '" alt="' + esc(atomizer.title || '') + '" class="wct-atomizer-img">');
                        }
                    }

                    $(this).show().removeClass('active');
                } else {
                    $(this).hide().removeClass('active');
                }
            });
        },

        selectAtomizerForSize: function ($panel, size, preferredAtomizerId) {
            var self = this;
            var selectedIndex = null;

            if (preferredAtomizerId) {
                $panel.find('.wct-atomizer:visible').each(function () {
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
                var $firstVisible = $panel.find('.wct-atomizer:visible:first');
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
            if (!$el.length || !$el.is(':visible')) {
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

        /* ---- Variant / size selection helpers ---- */

        /**
         * Select a variant by index. triggerNotify=false during auto-selection.
         */
        selectVariant: function (tabKey, vIndex, triggerNotify) {
            var $tabPanel = this.$container.find('.wct-panel[data-tab="' + tabKey + '"]');
            var tabData = this.data.tabs[tabKey];
            if (!tabData || !tabData.variants) { return; }

            var variant = tabData.variants[vIndex];
            if (!variant) { return; }

            $tabPanel.find('.wct-variant').removeClass('selected');
            $tabPanel.find('.wct-variant[data-variant-index="' + vIndex + '"]').addClass('selected');

            this.state.tab = tabKey;
            this.state.variant = variant;
            this.state.size = null;
            this.state.atomizer = null;

            if (!variant.available) {
                if (triggerNotify) {
                    this.openNotifyPanel(
                        $tabPanel.find('.wct-variant[data-variant-index="' + vIndex + '"]'),
                        variant.key || String(variant.index || vIndex),
                        {
                            product_id: this.data.product_id,
                            tab: tabKey,
                            key: variant.key || '',
                            label: variant.key || String(variant.index || vIndex),
                        }
                    );
                }
                this.updateSummary();
                return;
            }

            this.closeNotifyPanel();
            $tabPanel.find('.wct-tab-desc').text(variant.desc || '');
            if (this.state.remembered[tabKey]) {
                this.state.remembered[tabKey].variantIndex = vIndex;
            }
            this.updateSummary();
        },

        /**
         * Select a rozpyv size.
         */
        selectRozpyvSize: function ($panel, size) {
            $panel.find('.wct-size-btn').removeClass('active');
            $panel.find('.wct-size-btn[data-size="' + size + '"]').addClass('active');

            this.closeNotifyPanel();

            var base = (this.data.tabs.rozpyv && this.data.tabs.rozpyv.base)
                ? this.data.tabs.rozpyv.base : {};
            this.state.tab = 'rozpyv';
            this.state.size = size;
            this.state.remembered.rozpyv.size = size;

            $panel.find('.wct-tab-desc').text(base.desc || '');
            $panel.find('.wct-atomizers-wrap').show();

            this.filterAtomizersForSize($panel, size);

            var preferredAtomizerId = this.state.remembered.rozpyv.atomizerId;
            this.selectAtomizerForSize($panel, size, preferredAtomizerId);

            this.updateSummary();
        },

        /* ---- Notify panel ---- */

        openNotifyPanel: function ($anchor, label, data) {
            var self = this;
            var $np = this.$container.find('.wct-notify-panel.wct-notify-floating');

            $np.find('.wct-notify-label-text').text(label);
            $np.find('.wct-notify-phone').val('');
            $np.find('.wct-notify-message')
                .text('')
                .removeClass('wct-notify-success wct-notify-error')
                .hide();
            $np.find('.wct-notify-submit').prop('disabled', false).text(i18n('notify_submit'));

            this.notifyTarget = data;

            // Insert below the grid/flex that contains the anchor element.
            var $grid = $anchor.closest('.wct-variants, .wct-sizes, .wct-atomizers');
            if ($grid.length) {
                $np.detach().insertAfter($grid).show();
            } else {
                $np.detach().insertAfter($anchor).show();
            }

            setTimeout(function () {
                self.$container.find('.wct-notify-panel.wct-notify-floating .wct-notify-phone').focus();
            }, 80);
        },

        closeNotifyPanel: function () {
            this.notifyTarget = null;
            this.$container.find('.wct-notify-panel.wct-notify-floating').hide();
        },

        submitNotify: function ($np) {
            var self = this;
            if (!$np || !$np.length) {
                $np = this.$container.find('.wct-notify-panel.wct-notify-floating');
            }
            var phone = $np.find('.wct-notify-phone').val().trim();
            var phoneRegex = /^\+?[0-9]{7,15}$/;

            if (!phone || !phoneRegex.test(phone)) {
                $np.find('.wct-notify-message')
                    .removeClass('wct-notify-success')
                    .addClass('wct-notify-error')
                    .text(i18n('notify_error_phone'))
                    .show();
                return;
            }

            var target = this.notifyTarget || {};
            var notifyUrl = window.wcProductTabs.notify_url || '';

            if (!notifyUrl) {
                $np.find('.wct-notify-message')
                    .removeClass('wct-notify-success')
                    .addClass('wct-notify-error')
                    .text(i18n('notify_error'))
                    .show();
                return;
            }

            // Build GET query string.
            var activeTab = target.tab || this.state.tab || '';
            var activeVariant = this.state.variant || {};
            var activeKey = target.key || activeVariant.key || '';
            
            if (activeTab === 'rozpyv' && !activeKey) {
                var rozpyvBase = (this.data && this.data.tabs && this.data.tabs.rozpyv && this.data.tabs.rozpyv.base) || {};
                activeKey = rozpyvBase.key || '';
            }

            var params = new URLSearchParams({
                phone: phone,
                product_id: String(target.product_id || (this.data ? this.data.product_id : 0) || 0),
                tab: activeTab,
                key: activeKey,
            });
            var sep = notifyUrl.indexOf('?') === -1 ? '?' : '&';
            var fullUrl = notifyUrl + sep + params.toString();

            var $btn = $np.find('.wct-notify-submit');
            $btn.prop('disabled', true).text('\u2026');

            fetch(fullUrl, { method: 'GET' })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    var $msg = $np.find('.wct-notify-message');
                    if (json && json.success) {
                        $msg.removeClass('wct-notify-error')
                            .addClass('wct-notify-success')
                            .text(i18n('notify_success'))
                            .show();
                        $btn.prop('disabled', true).text(i18n('notify_submit'));
                    } else {
                        $msg.removeClass('wct-notify-success')
                            .addClass('wct-notify-error')
                            .text((json && json.message) ? json.message : i18n('notify_error'))
                            .show();
                        $btn.prop('disabled', false).text(i18n('notify_submit'));
                    }
                })
                .catch(function () {
                    $np.find('.wct-notify-message')
                        .removeClass('wct-notify-success')
                        .addClass('wct-notify-error')
                        .text(i18n('notify_error'))
                        .show();
                    $btn.prop('disabled', false).text(i18n('notify_submit'));
                });
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

    function getAtomizerImageForSize(atomizer, size) {
        var option = getRozpyvAtomizerOption(atomizer, size);
        var image = option && option.image ? option.image : (atomizer && atomizer.image ? atomizer.image : '');

        return resolveAtomizerImage(image);
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

    function resolveAtomizerImage(image) {
        var raw = String(image || '').trim();

        if (!raw) {
            return '';
        }

        if (/^(https?:)?\/\//i.test(raw) || raw.indexOf('data:image/') === 0) {
            return raw;
        }

        if (raw.charAt(0) === '/') {
            return raw;
        }

        var base = (window.wcProductTabs && window.wcProductTabs.atomizers_url)
            ? String(window.wcProductTabs.atomizers_url)
            : '';

        if (!base) {
            return raw;
        }

        if (base.slice(-1) !== '/') {
            base += '/';
        }

        return base + raw.replace(/^\/+/, '');
    }

    /* =======================================================================
     * Boot
     * ======================================================================= */

    $(document).ready(function () {
        ProductTabs.init();
    });

}(jQuery));

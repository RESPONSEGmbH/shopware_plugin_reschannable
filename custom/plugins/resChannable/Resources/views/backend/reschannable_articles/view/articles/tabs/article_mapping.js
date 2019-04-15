/* {namespace name=backend/reschannable_articles/view/articles/tabs/article_mapping} */

Ext.define('Shopware.apps.ReschannableArticles.view.articles.tabs.ArticleMapping', {

   /**
    * Parent Element Ext.container.Container
    * @string
    */
    extend:'Ext.form.Panel',

    /**
     * Register the alias for this class.
     * @string
     */
    alias:'widget.reschannablearticles-articles-tabs-article_mapping',

    /**
     * Base class of the component
     * @string
     */
    cls: 'shopware-form',

    /**
     * Specifies the border for this component. The border can be a single numeric
     * value to apply to all sides or it can be a CSS style specification for each
     * style, for example: '10 5 3 10'.
     *
     * Default: 0
     * @integer
     */
    border: 0,

    /**
     * Display the the contents of this tab immediately
     * @boolean
     */
    autoShow : true,

    /**
     * Layout configuration
     * @object
     */
    layout: {
        type: 'hbox',
        pack: 'start',
        align: 'stretch'
    },

    /**
     * Body padding
     * @integer
     */
    bodyPadding: 10,

    /**
     * Available action buttons
     * @array
     */
    defaultButtons: [ 'add', 'remove' ],

    /**
     * Default text which are used for the tooltip on the button.
     * @object
     */
    buttonsText: {
        add: "{s name=button_add}Add{/s}",
        remove: "{s name=button_remove}Remove{/s}"
    },

    /**
     * Creates the necessary event listener for this
     * specific controller and opens a new Ext.window.Window
     * to display the sub-application
     *
     * @return void
     */
    init: function() {
        var me = this;

        me.control({
            'reschannablearticles-articles-tabs-article_mapping':{
                recordloaded : me.onRecordLoaded
            }/*,
            // Save button in settings tab
            'category-category-tabs-settings button[action=categorySaveButton]':{
                'click' : me.onSaveSettings
            }*/
        });
    },
    /**
     * Reacts if the event recordloaded is fired and hides or shows the template selection based
     * on the parent ID of the loaded record.
     *
     * @event recordloaded
     * @param record [Ext.data.Model]
     * @return void
     */
    onRecordLoaded : function(record, treeRecord) {
        var me = this,
            form = me.getSettingsForm(),
            store = form.templateComboBox.getStore(),
            records = store.getRange(),
            customTpl = record.get('template'),
            i = 0,
            count = records.length;

        me.selectorView = Ext.create('Shopware.apps.ReschannableArticles.view.articles.tabs.ArticleMapping', {
            availableProductsStore: me.subApplication.availableProductsStore,
            assignedProductsStore: me.subApplication.assignedProductsStore
            //record: me.detailRecord
        });

    },
    /**
     * Initialize the Shopware.apps.Category.view.category.tabs.ArticleMapping and defines the necessary
     * default configuration
     *
     * @returns { Void }
     */
    initComponent:function () {
        var me = this;

        me.fromGrid = me.createFromGrid();
        me.buttonContainer = me.createActionButtons();
        me.toGrid = me.createToGrid();

        me.items = [ me.fromGrid, me.buttonContainer, me.toGrid ];
        me.addEvents('storeschanged', 'add', 'remove');
        me.on('storeschanged', me.onStoresChanged, me);

        me.callParent(arguments);
    },

    /**
     * Creates the `from` grid
     * @returns { Ext.grid.Panel }
     */
    createFromGrid: function() {
        var me = this, grid, toolbar;

        grid = Ext.create('Ext.grid.Panel', {
            internalTitle: 'from',
            title: '{s name=available_articles}Available Articles{/s}',
            flex: 1,
            store: me.availableProductsStore.load(),
            selModel: me.createSelectionModel(),
            viewConfig: { loadMask: false, plugins: me.createGridDragAndDrop() },
            bbar: me.createPagingToolbar(me.availableProductsStore),
            columns: me.getColumns()
        });

        toolbar = me.createSearchToolbar(grid);
        grid.addDocked(toolbar);

        return grid;
    },

    /**
     * Creates the `to` grid
     * @returns { Ext.grid.Panel }
     */
    createToGrid: function() {
        var me = this, grid, toolbar;

        grid =  Ext.create('Ext.grid.Panel', {
            internalTitle: 'to',
            title: '{s name=mapped_articles}Mapped Articles{/s}',
            flex: 1,
            store: me.assignedProductsStore.load(),
            selModel: me.createSelectionModel(),
            viewConfig: { loadMask: false, plugins: me.createGridDragAndDrop() },
            bbar: me.createPagingToolbar(me.assignedProductsStore),
            columns: me.getColumns()
        });

        toolbar = me.createSearchToolbar(grid);
        grid.addDocked(toolbar);

        return grid;
    },

    /**
     * Creates the action buttons which are located between the `fromGrid` (on the left side)
     * and the `toGrid` (on the right side).
     *
     * The buttons are placed in an `Ext.container.Container` to apply the necessary layout
     * on it.
     *
     * @returns { Ext.container.Container }
     */
    createActionButtons: function() {
        var me = this;

        me.actionButtons = [];
        Ext.Array.forEach(me.defaultButtons, function(name) {

            var button = Ext.create('Ext.Button', {
                tooltip: me.buttonsText[name],
                cls: Ext.baseCSSPrefix + 'form-itemselector-btn',
                iconCls: Ext.baseCSSPrefix + 'form-itemselector-' + name,
                action: name,
                disabled: true,
                navBtn: true,
                margin: '4 0 0 0',
                listeners: {
                    scope: me,
                    click: function() {
                        me.fireEvent(name, me);
                    }
                }
            });
            me.actionButtons.push(button);
        });


        return Ext.create('Ext.container.Container', {
            margins: '0 4',
            items:  me.actionButtons,
            width: 22,
            layout: {
                type: 'vbox',
                pack: 'center'
            }
        });
    },

    /**
     * Creates a paging toolbar based of the incoming store
     *
     * @param { Ext.data.Store } store
     * @returns { Ext.toolbar.Paging }
     */
    createPagingToolbar: function(store) {

        return Ext.create('Ext.toolbar.Paging', {
            store: store,
            displayInfo: true
        });
    },

    /**
     * Creates a toolbar which could be docked to the top of
     * a grid panel and contains a searchfield to filter
     * the associated grid panel.
     *
     * @returns { Ext.toolbar.Toolbar }
     */
    createSearchToolbar: function(cmp) {
        var me = this, searchField;

        searchField = Ext.create('Ext.form.field.Text', {
            name: 'searchfield',
            dock: 'top',
            cls: 'searchfield',
            width: 270,
            emptyText: 'Search...',
            enableKeyEvents: true,
            checkChangeBuffer: 500,
            listeners: {
                change: function(field, value) {
                    me.fireEvent('search', value, cmp);
                }
            }
        });

        return Ext.create('Ext.toolbar.Toolbar', {
            ui: 'shopware-ui',
            padding: '2 0',
            items: [ '->', searchField, ' ' ]
        });
    },

    /**
     * Creates the selection model which is used by both grids.
     *
     * @returns { Ext.selection.CheckboxModel }
     */
    createSelectionModel: function() {
        return Ext.create('Ext.selection.CheckboxModel');
    },

    createGridDragAndDrop: function() {
        return Ext.create('Ext.grid.plugin.DragDrop', {
            ddGroup: 'reschannablearticles-product-assignment-grid-dd'
        });
    },

    /**
     * Creates the necessary columns for both grids. Please
     * note that the `name` column has a specific renderer.
     *
     * @returns { Array }
     */
    getColumns: function() {
        var me = this;

        return [{
            header: '{s name=columns/article_number}Article Number{/s}',
            flex: 1,
            dataIndex: 'number'
        }, {
            header: '{s name=columns/article_name}Article Name{/s}',
            flex: 2,
            dataIndex: 'name',
            renderer: me.nameColumnRenderer
        }, {
            header: '{s name=columns/supplier_name}Supplier Name{/s}',
            flex: 1,
            dataIndex: 'supplierName'
        }];
    },

    /**
     * Renders the incoming column value into `strong` tags.
     *
     * @param { String } value
     * @returns { String } formatted string
     */
    nameColumnRenderer: function(value) {
        return Ext.String.format('<strong>[0]</strong>', value);
    },

    /**
     * Event listener which will be fired when the user selects
     * an another category in the tree.
     *
     * The method reconfigures the stores and reloads them.
     *
     * @return { Void }
     */
    onStoresChanged: function() {
        var me = this,
            fromStore = me.availableProductsStore,
            toStore = me.assignedProductsStore;

        // Set the new stores
        me.fromGrid.reconfigure(fromStore);
        me.toGrid.reconfigure(toStore);

        // Reload the stores
        me.fromGrid.getStore().load();
        me.toGrid.getStore().load();
    }
});

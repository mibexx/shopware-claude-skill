import template from './swag-example-list.html.twig';

const { Mixin } = Shopware;
const { Criteria } = Shopware.Data;

export default {
    template,

    inject: ['repositoryFactory', 'acl'],

    mixins: [Mixin.getByName('listing')],

    data() {
        return {
            items: null,
            isLoading: true,
            sortBy: 'name',
            sortDirection: 'ASC',
            total: 0,
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('swag_example');
        },

        columns() {
            return [
                {
                    property: 'name',
                    dataIndex: 'name',
                    label: 'swag-example.list.columnName',
                    routerLink: 'swag.example.detail',
                    inlineEdit: 'string',
                    allowResize: true,
                    primary: true,
                },
                {
                    property: 'active',
                    dataIndex: 'active',
                    label: 'swag-example.list.columnActive',
                    inlineEdit: 'boolean',
                    align: 'center',
                },
            ];
        },

        criteria() {
            const criteria = new Criteria(this.page, this.limit);

            criteria.setTerm(this.term);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection, this.naturalSorting));

            return criteria;
        },
    },

    methods: {
        // Called by the "listing" mixin (page change, sorting, route change)
        async getList() {
            this.isLoading = true;

            try {
                const result = await this.repository.search(this.criteria);

                this.items = result;
                this.total = result.total;
            } finally {
                this.isLoading = false;
            }
        },

        updateTotal({ total }) {
            this.total = total;
        },
    },
};

import template from './swag-example-detail.html.twig';

const { Mixin } = Shopware;
const { mapPropertyErrors } = Shopware.Component.getComponentHelper();

export default {
    template,

    inject: ['repositoryFactory', 'acl'],

    mixins: [Mixin.getByName('notification')],

    props: {
        exampleId: {
            type: String,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            example: null,
            isLoading: false,
            isSaveSuccessful: false,
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(this.example?.name),
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('swag_example');
        },

        // creates exampleNameError and exampleTechnicalNameError for :error in the template
        ...mapPropertyErrors('example', ['name', 'technicalName']),
    },

    watch: {
        exampleId() {
            this.createdComponent();
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            if (this.exampleId) {
                void this.loadEntity();
                return;
            }

            this.example = this.repository.create();
        },

        async loadEntity() {
            this.isLoading = true;

            try {
                this.example = await this.repository.get(this.exampleId);
            } catch {
                this.createNotificationError({
                    message: this.$t('global.notification.notificationLoadingDataErrorMessage'),
                });
            } finally {
                this.isLoading = false;
            }
        },

        async onSave() {
            if (!this.acl.can('swag_example.editor') && this.exampleId) {
                return;
            }

            this.isLoading = true;

            try {
                await this.repository.save(this.example);
                this.isSaveSuccessful = true;

                if (this.exampleId === null) {
                    // switch to the detail route after creation
                    void this.$router.push({ name: 'swag.example.detail', params: { id: this.example.id } });
                    return;
                }

                // the local entity is not updated by save(), so reload
                await this.loadEntity();
            } catch {
                this.createNotificationError({
                    message: this.$t('global.notification.notificationSaveErrorMessageRequiredFieldsInvalid'),
                });
            } finally {
                this.isLoading = false;
            }
        },

        onCancel() {
            void this.$router.push({ name: 'swag.example.index' });
        },
    },
};

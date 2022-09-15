import {defineStore} from 'pinia';
import axios from 'axios';

export const useCart = defineStore('cart', {
    persist: true,

    state: () => {
        return {
            name: '',
            email: '',
            market: null,
            domain: null,
            selectedItems: [],
        };
    },

    getters: {
        valid() {
            return this.name.length > 0 && this.email.length > 0
                && this.market !== null && this.domain !== null
                && this.selectedItems.length > 0;
        },
    },

    actions: {
        setSelectedItems(items) {
            this.selectedItems = items;
        },

        createOrder() {
            if (!this.valid) {
                alert('Invalid validation');
                return;
            }

            axios.post(route('api.checkout.order'), this.$state)
                .then(({data}) => window.location.href = route('checkout.thank_you'))
                .catch(error => {
                    console.error('Failed to create order', error);
                    alert('Whoops, something went wrong... Please try again later.');
                });
        },
    },
});

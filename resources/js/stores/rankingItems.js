import { defineStore } from 'pinia'

export const useRankingItemsStore = defineStore('rankingItems', {
    state: () => {
        return {
            items: [],
        };
    },

    getters: {
        selectedItems: (state) => state.items.filter(item => item.selected),
    },

    actions: {
        setItems(items) {
            this.items = items.map(item => ({...item, selected: false}));
        },

        toggleAll(value) {
            this.items.forEach(item => item.selected = value);
        },

        update() {
            document.getElementById('check-all-items').indeterminate = !this.items.every(items => items.selected) && this.items.some(items => items.selected);
        },
    },
})
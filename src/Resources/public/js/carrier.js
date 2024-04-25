window.addEventListener('DOMContentLoaded', (event) => {
    const pickupPointDelivery = document.getElementById('sylius_shipping_method_pickupPointDelivery');
    const carrierCodeContainer = document.getElementById('sylius_shipping_method_carrierCode').closest('div.field');

    if(null === pickupPointDelivery || null === carrierCodeContainer) {
        return;
    }

    carrierCodeContainer.style.display = pickupPointDelivery.checked ? 'block' : 'none';

    pickupPointDelivery.addEventListener('change', (e) => {
        carrierCodeContainer.style.display = e.currentTarget.checked ? 'block' : 'none';
    });
});

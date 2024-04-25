class PickupPointManager {
  /**
   * @type {{ endpoint: string, allowedShippingMethods: Array, shippingMethodSelector: string, insertHtmlCallback: function(HTMLInputElement) }}
   */
  #config = {};

  /**
   * Holds the pickup points data when loaded, indexed by shipping method
   *
   * @type {Object.<string, object>}
   */
  #data = {};

  /**
   * @param {Object} config
   * @param {string} config.endpoint
   * @param {Array} config.allowedShippingMethods
   * @param {string} config.shippingMethodSelector
   * @param {function(HTMLInputElement)} config.insertHtmlCallback
   */
  constructor(config) {
    this.#config = Object.assign({
      endpoint: null,
      allowedShippingMethods: [],
      shippingMethodSelector: 'input[type="radio"][name^="sylius_checkout_select_shipping[shipments]"]',
      insertHtmlCallback: (radio) => {
        radio.closest('.item').insertAdjacentHTML('afterend', this.getHtml(radio.value));
      },
    }, config);
  }

  init() {
    window.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll(this.#config.shippingMethodSelector).forEach((radio) => {
        this.#initShippingMethod(radio);
      });
    });
  }

  /**
   * @param {HTMLInputElement} radio
   */
  #initShippingMethod(radio) {
    radio.addEventListener('change', () => {
      this.#removePickupPoints();
    });

    if(this.#config.allowedShippingMethods.length > 0 && !this.#config.allowedShippingMethods.includes(radio.value)) {
      return;
    }

    this.#load(radio);

    if(radio.checked) {
      this.#insertHtml(radio);
    }

    radio.addEventListener('change', (e) => {
      if(!e.currentTarget.checked) {
        return;
      }

      this.#insertHtml(e.currentTarget);
    });
  }

  /**
   * @param {HTMLInputElement} radio
   */
  #insertHtml(radio)
  {
    if(!this.hasData(radio.value)) {
      radio.dispatchEvent(new CustomEvent('pickup_point_manager:loading', { bubbles: true, detail: { radio: radio, shippingMethod: radio.value } }));

      setTimeout(() => {
        this.#insertHtml(radio);
      }, 100);

      return;
    }

    radio.dispatchEvent(new CustomEvent('pickup_point_manager:insert_html', { bubbles: true, detail: { radio: radio, shippingMethod: radio.value } }));

    this.#config.insertHtmlCallback.call(this, radio);
  }

  /**
   * Removes all pickup points from the DOM
   */
  #removePickupPoints() {
    document.querySelectorAll('.pickup-points-container').forEach((element) => {
      element.remove();
    });
  }

  /**
   * @param {HTMLInputElement} radio
   */
  async #load(radio) {
    if(this.hasData(radio.value)) {
      return;
    }

    let response;

    try {
      response = await fetch(`${this.#config.endpoint}?shippingMethod=${radio.value}`);
    } catch (e) {
      console.error('Failed to load pickup point data', e);

      return;
    }

    if(!response?.ok) {
      console.error('Failed to load pickup point data', response.status, response.statusText);

      return;
    }

    const data = await response.json();
    data.html = data.html.replace('%fieldName%', radio.name.replace('[method]', '[shipmondoPickupPoint]'));

    this.#data[radio.value] = data;
  }

  /**
   * @param {string} shippingMethod
   * @return {boolean}
   */
  hasData(shippingMethod)
  {
    return Object.hasOwn(this.#data, shippingMethod);
  }

  /**
   * @param {string} shippingMethod
   * @return {string}
   */
  getHtml(shippingMethod)
  {
    if(!this.hasData(shippingMethod)) {
      throw new Error('No pickup point data found for shipping method ' + shippingMethod);
    }

    return this.#data[shippingMethod].html;
  }
}

export { PickupPointManager };

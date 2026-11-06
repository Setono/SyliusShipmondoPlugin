class PickupPointManager {
  /**
   * @type {{ endpoint: string, allowedShippingMethods: Array, shippingMethodSelector: string, insertHtmlCallback: function(HTMLInputElement), loadFailedHtml: string }}
   */
  #config = {};

  /**
   * Holds the pickup points data when loaded, indexed by shipping method
   *
   * @type {Object.<string, object>}
   */
  #data = {};

  /**
   * The shipping methods whose pickup points are being loaded
   *
   * @type {Set<string>}
   */
  #loading = new Set();

  /**
   * The shipping methods whose pickup points could not be loaded. Choosing such a shipping method again, or the Try again
   * button of the message shown instead of its pickup points, loads them again
   *
   * @type {Set<string>}
   */
  #failed = new Set();

  /**
   * @param {Object} config
   * @param {string} config.endpoint
   * @param {Array} config.allowedShippingMethods
   * @param {string} config.shippingMethodSelector
   * @param {function(HTMLInputElement)} config.insertHtmlCallback
   * @param {string} config.loadFailedHtml shown instead of the pickup points when they could not be loaded. Like the
   *   pickup points, it is a .pickup-points-container, and clicking an element in it with the
   *   data-pickup-point-manager-load-again attribute loads them again
   */
  constructor(config) {
    this.#config = Object.assign({
      endpoint: null,
      allowedShippingMethods: [],
      shippingMethodSelector: 'input[type="radio"][name^="sylius_checkout_select_shipping[shipments]"]',
      insertHtmlCallback: function (radio) {
        radio.closest('.item').insertAdjacentHTML('afterend', this.getHtml(radio.value));
      },
      loadFailedHtml: '<div class="item pickup-points-container"><div class="content"><div class="ui negative message"><p>The pickup points could not be loaded.</p><button type="button" class="ui small button" data-pickup-point-manager-load-again>Try again</button></div></div></div>',
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

      this.#loadAgain(e.currentTarget);
    });
  }

  /**
   * Loads the shipping method's pickup points again if they could not be loaded, and shows them, or that they could
   * not be loaded. The customer chose the shipping method, or clicked Try again
   *
   * @param {HTMLInputElement} radio
   */
  #loadAgain(radio) {
    this.#failed.delete(radio.value);
    this.#removePickupPoints();
    this.#insertHtml(radio);
  }

  /**
   * @param {HTMLInputElement} radio
   */
  #insertHtml(radio) {
    // the customer selected another shipping method while the pickup points were loading. Its change handler shows
    // the pickup points again if the customer selects this shipping method again
    if(!radio.checked) {
      return;
    }

    // stop waiting, and tell the customer
    if(this.#failed.has(radio.value)) {
      this.#removePickupPoints();
      this.#config.insertHtmlCallback.call(this, radio);

      // a selected shipping method can't be chosen again, e.g. when it is the only one, so the message offers to try again
      document.querySelectorAll('.pickup-points-container [data-pickup-point-manager-load-again]').forEach((button) => {
        button.addEventListener('click', () => this.#loadAgain(radio));
      });

      radio.dispatchEvent(new CustomEvent('pickup_point_manager:load_failed', { bubbles: true, detail: { radio: radio, shippingMethod: radio.value } }));

      return;
    }

    if(!this.hasData(radio.value)) {
      this.#load(radio);

      radio.dispatchEvent(new CustomEvent('pickup_point_manager:loading', { bubbles: true, detail: { radio: radio, shippingMethod: radio.value } }));

      setTimeout(() => {
        this.#insertHtml(radio);
      }, 100);

      return;
    }

    // selecting the shipping method again while its pickup points were loading waits for them twice
    this.#removePickupPoints();
    this.#config.insertHtmlCallback.call(this, radio);

    radio.dispatchEvent(new CustomEvent('pickup_point_manager:inserted_html', { bubbles: true, detail: { radio: radio, shippingMethod: radio.value } }));
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
    if(this.hasData(radio.value) || this.#loading.has(radio.value)) {
      return;
    }

    this.#loading.add(radio.value);

    try {
      const data = await this.#fetch(radio);
      data.html = data.html.replaceAll('%fieldName%', radio.name.replace('[method]', '[shipmondoPickupPoint]'));

      this.#data[radio.value] = data;
    } catch (e) {
      console.error('Failed to load pickup point data', e);

      this.#failed.add(radio.value);
    } finally {
      this.#loading.delete(radio.value);
    }
  }

  /**
   * @param {HTMLInputElement} radio
   * @return {Promise<object>} rejects when the pickup points could not be loaded
   */
  async #fetch(radio) {
    const response = await fetch(`${this.#config.endpoint}?shippingMethod=${radio.value}`);
    if(!response.ok) {
      throw new Error(`HTTP ${response.status} ${response.statusText}`);
    }

    return response.json();
  }

  /**
   * @param {string} shippingMethod
   * @return {boolean}
   */
  hasData(shippingMethod) {
    return Object.hasOwn(this.#data, shippingMethod);
  }

  /**
   * @param {string} shippingMethod
   * @return {string}
   */
  getHtml(shippingMethod) {
    if(this.#failed.has(shippingMethod)) {
      return this.#config.loadFailedHtml;
    }

    if(!this.hasData(shippingMethod)) {
      throw new Error('No pickup point data found for shipping method ' + shippingMethod);
    }

    return this.#data[shippingMethod].html;
  }
}

export { PickupPointManager };

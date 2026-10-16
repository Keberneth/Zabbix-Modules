class CWidgetPatchCalendar extends CWidget {

	/**
	 * Active calendar widgets, refreshed after a maintenance is edited from any of them.
	 *
	 * @type {Set<CWidgetPatchCalendar>}
	 */
	static instances = new Set();

	static is_edit_listener_registered = false;

	onInitialize() {
		// View selected in the widget (null = the configured one) and the page offset from today.
		this._calendar_view = null;
		this._calendar_offset = 0;
	}

	onStart() {
		this._events = {
			...this._events,

			click: (e) => {
				const nav = e.target.closest('.js-pc-nav');

				if (nav !== null) {
					if (!nav.disabled) {
						this._calendar_offset = parseInt(nav.dataset.offset, 10);
						this._startUpdating();
					}

					return;
				}

				const view = e.target.closest('.js-pc-view');

				if (view !== null && !view.classList.contains('pc-view-selected')) {
					this._calendar_view = parseInt(view.dataset.view, 10);
					this._calendar_offset = 0;
					this._startUpdating();
				}
			}
		};
	}

	onActivate() {
		this._body.addEventListener('click', this._events.click);

		CWidgetPatchCalendar.instances.add(this);
		CWidgetPatchCalendar.registerEditListener();
	}

	onDeactivate() {
		this._body.removeEventListener('click', this._events.click);

		CWidgetPatchCalendar.instances.delete(this);
	}

	onDestroy() {
		CWidgetPatchCalendar.instances.delete(this);
	}

	onResize() {
		if (this._state === WIDGET_STATE_ACTIVE) {
			this.#fitDays();
		}
	}

	getUpdateRequestData() {
		return {
			...super.getUpdateRequestData(),
			calendar_view: this._calendar_view ?? undefined,
			calendar_offset: this._calendar_offset
		};
	}

	setContents(response) {
		super.setContents(response);

		this.#fitDays();
	}

	/**
	 * Hide the month view entries that do not fit into their day and show "+N more" with the full list instead.
	 */
	#fitDays() {
		for (const entries of this._body.querySelectorAll('.pc-day-entries')) {
			entries.querySelector('.pc-more')?.remove();

			const chips = [...entries.querySelectorAll('.pc-chip')];

			for (const chip of chips) {
				chip.classList.remove('pc-hidden');
			}

			if (entries.scrollHeight <= entries.clientHeight + 1) {
				continue;
			}

			const more = document.createElement('div');

			more.classList.add('pc-more');
			more.dataset.hintbox = '1';
			more.dataset.hintboxStatic = '1';
			more.dataset.hintboxContents = entries.dataset.dayHint;
			entries.appendChild(more);

			for (let i = chips.length - 1, hidden = 1; i >= 0; i--, hidden++) {
				chips[i].classList.add('pc-hidden');
				more.textContent = sprintf(t('+%1$s more'), hidden);

				if (entries.scrollHeight <= entries.clientHeight + 1) {
					break;
				}
			}
		}
	}

	/**
	 * Open the maintenance form from the "Edit maintenance" button in a window hint.
	 *
	 * Hints live outside the widget, so one document listener serves all calendar widgets.
	 */
	static registerEditListener() {
		if (CWidgetPatchCalendar.is_edit_listener_registered) {
			return;
		}

		CWidgetPatchCalendar.is_edit_listener_registered = true;

		document.addEventListener('click', (e) => {
			const button = e.target.closest('.js-pc-edit-maintenance');

			if (button === null) {
				return;
			}

			const overlay = PopUp('maintenance.edit', {maintenanceid: button.dataset.maintenanceid}, {
				dialogueid: 'maintenance-edit',
				dialogue_class: 'modal-popup-large',
				prevent_navigation: true
			});

			overlay.$dialogue[0].addEventListener('dialogue.submit', (e) => {
				clearMessages();
				addMessage(makeMessageBox('good', e.detail.messages ?? [], e.detail.title));

				for (const widget of CWidgetPatchCalendar.instances) {
					if (widget._state === WIDGET_STATE_ACTIVE) {
						widget._startUpdating();
					}
				}
			}, {once: true});
		});
	}
}

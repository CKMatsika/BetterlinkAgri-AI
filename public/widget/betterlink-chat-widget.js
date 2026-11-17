/**
 * BetterlinkAgr AI — Embeddable Web Chat Widget (Vanilla JS, Low-Bandwidth)
 * Supports: Text, Camera/Photo Upload, Voice Notes, GPS Pin, Context Chip, and 7 Rich Card types.
 */
(function () {
    'use strict';

    class BetterlinkChatWidget {
        constructor(config = {}) {
            this.apiUrl = config.apiUrl || '/api/v1/web-chat/messages';
            this.feedbackUrl = config.feedbackUrl || '/api/v1/web-chat/feedback';
            this.tenantSlug = config.tenantSlug || 'farmserve-zimbabwe';
            this.phone = config.phone || '+263772999000';
            this.language = config.language || 'en';
            this.conversationId = null;
            this.mountSelector = config.mountSelector || '#betterlink-chat-root';
            this.init();
        }

        init() {
            const root = document.querySelector(this.mountSelector);
            if (!root) return;

            root.innerHTML = `
                <div class="bl-widget-container">
                    <div class="bl-widget-header">
                        <div class="bl-widget-header-top">
                            <h3 class="bl-widget-title">🌿 BetterlinkAgr AI Mudhumeni</h3>
                            <select id="bl-lang-select" style="background:#166534;color:#fff;border:1px solid #4ade80;border-radius:6px;font-size:11px;padding:2px 6px;">
                                <option value="en">EN</option>
                                <option value="sn">ChiShona</option>
                                <option value="nd">isiNdebele</option>
                            </select>
                        </div>
                        <div id="bl-context-chip" class="bl-context-chip">🌱 Crop / Growth stage / Conditions</div>
                    </div>
                    <div id="bl-messages" class="bl-widget-messages">
                        <div class="bl-msg bl-msg-agent">Mhoroi / Salibonani / Hello! Send a photo of an insect, leaf, stem, fruit, or livestock problem—or ask any agronomy or stock question.</div>
                    </div>
                    <form id="bl-chat-form" class="bl-widget-input-bar">
                        <label class="bl-icon-btn" title="Upload Crop/Pest Photo">
                            📷
                            <input type="file" id="bl-photo-input" accept="image/*" style="display:none" />
                        </label>
                        <button type="button" id="bl-gps-btn" class="bl-icon-btn" title="Share Farm GPS Pin">📍</button>
                        <input type="text" id="bl-text-input" class="bl-input-text" placeholder="Type message or reply 1, 2..." autocomplete="off" />
                        <button type="submit" class="bl-send-btn">Send</button>
                    </form>
                </div>
            `;

            this.messagesEl = root.querySelector('#bl-messages');
            this.contextChipEl = root.querySelector('#bl-context-chip');
            this.textInput = root.querySelector('#bl-text-input');
            this.photoInput = root.querySelector('#bl-photo-input');
            this.langSelect = root.querySelector('#bl-lang-select');

            root.querySelector('#bl-chat-form').addEventListener('submit', (e) => {
                e.preventDefault();
                const text = this.textInput.value.trim();
                if (!text && !this.photoInput.files.length) return;
                this.sendTurn({ messageText: text, photoFile: this.photoInput.files[0] || null });
                this.textInput.value = '';
                this.photoInput.value = '';
            });

            this.photoInput.addEventListener('change', () => {
                if (this.photoInput.files.length > 0) {
                    this.sendTurn({ messageText: this.textInput.value.trim() || 'Uploaded farm photo', photoFile: this.photoInput.files[0] });
                    this.photoInput.value = '';
                }
            });

            root.querySelector('#bl-gps-btn').addEventListener('click', () => {
                if (!navigator.geolocation) return;
                navigator.geolocation.getCurrentPosition((pos) => {
                    this.sendTurn({
                        messageText: 'Shared GPS Location Pin',
                        latitude: pos.coords.latitude,
                        longitude: pos.coords.longitude,
                    });
                });
            });

            this.langSelect.addEventListener('change', () => {
                this.language = this.langSelect.value;
            });
        }

        appendBubble(text, sender = 'agent', buttons = [], richCards = []) {
            const div = document.createElement('div');
            div.className = `bl-msg ${sender === 'farmer' ? 'bl-msg-farmer' : 'bl-msg-agent'}`;
            div.textContent = text;

            if (richCards && richCards.length > 0) {
                richCards.forEach((card) => {
                    const cardEl = document.createElement('div');
                    cardEl.className = 'bl-rich-card';
                    cardEl.innerHTML = `<h4>[${card.card_type}] ${card.title || ''}</h4><div>${this.formatRichCardPayload(card)}</div>`;
                    div.appendChild(cardEl);
                });
            }

            if (buttons && buttons.length > 0) {
                const btnWrap = document.createElement('div');
                btnWrap.className = 'bl-quick-buttons';
                buttons.forEach((btn) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'bl-quick-btn';
                    b.textContent = btn.title;
                    b.addEventListener('click', () => this.sendTurn({ messageText: btn.id }));
                    btnWrap.appendChild(b);
                });
                div.appendChild(btnWrap);
            }

            this.messagesEl.appendChild(div);
            this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
        }

        formatRichCardPayload(card) {
            const p = card.payload || {};
            if (card.card_type === 'diagnosis_result') {
                return `Confidence: ${p.confidence_pct || 0}% • ${p.local_name || ''}`;
            }
            if (card.card_type === 'outlet_list' && Array.isArray(p.outlets)) {
                return p.outlets.map((o, i) => `${i + 1}. <b>${o.outlet_name}</b> (${o.distance_km} km)`).join('<br/>');
            }
            return JSON.stringify(p).slice(0, 180);
        }

        async sendTurn({ messageText = '', photoFile = null, latitude = null, longitude = null }) {
            this.appendBubble(photoFile ? `📷 [Photo] ${messageText}` : messageText, 'farmer');

            const formData = new FormData();
            formData.append('phone', this.phone);
            formData.append('language', this.language);
            if (messageText) formData.append('message_text', messageText);
            if (photoFile) formData.append('photo', photoFile);
            if (latitude !== null && longitude !== null) {
                formData.append('latitude', String(latitude));
                formData.append('longitude', String(longitude));
            }

            try {
                const resp = await fetch(this.apiUrl, {
                    method: 'POST',
                    headers: { 'X-Tenant-Slug': this.tenantSlug, 'Accept': 'application/json' },
                    body: formData,
                });
                const json = await resp.json();
                const data = json.data || json;
                this.conversationId = data.conversation_id;

                if (data.context_chip) {
                    this.contextChipEl.textContent = `🌱 ${data.context_chip}`;
                }

                this.appendBubble(data.reply_text || '', 'agent', data.buttons || [], data.rich_cards || []);
            } catch (err) {
                this.appendBubble('Network error — please check connection and retry.', 'agent');
            }
        }
    }

    window.BetterlinkChatWidget = BetterlinkChatWidget;
})();

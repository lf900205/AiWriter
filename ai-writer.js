/**
 * Typecho AI写作助手插件 - 智能排版合并版 v2.1.0
 * (支持前端选择作家风格)
 */

(function() {
    'use strict';
    
    const CONFIG = window.AiWriterConfig || {
        ajaxUrl: '/ai-writer/process',
        defaultService: 'deepseek',
        enableSlug: '1',
        debugMode: '0',
        version: '2.1.0'
    };
    
    let isProcessing = false;
    
    // ==================== 工具函数 ====================
    function showMessage(message, type = 'info', duration = 5000) {
        const oldMsg = document.querySelector('.ai-writer-message');
        if (oldMsg) {
            oldMsg.classList.remove('show');
            setTimeout(() => oldMsg.remove(), 300);
        }
        const msg = document.createElement('div');
        msg.className = `ai-writer-message ${type}`;
        msg.setAttribute('role', 'alert');
        let icon = 'ℹ';
        if (type === 'success') icon = '✓';
        if (type === 'error') icon = '✗';
        if (type === 'warning') icon = '⚠';
        msg.innerHTML = `<span class="ai-message-icon">${icon}</span><span class="ai-message-text">${escapeHtml(message)}</span>`;
        document.body.appendChild(msg);
        setTimeout(() => msg.classList.add('show'), 10);
        setTimeout(() => {
            msg.classList.remove('show');
            setTimeout(() => msg.remove(), 300);
        }, duration);
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function triggerEvent(element, eventName) {
        if (!element) return;
        const event = new Event(eventName, { bubbles: true, cancelable: true });
        element.dispatchEvent(event);
    }
    
    function generateSlug(text) {
        if (!text) return 'post-' + Date.now();
        return text.toLowerCase()
            .replace(/[^\w\s-]/g, '')
            .replace(/[\s_-]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .substring(0, 50);
    }
    
    function highlightEditor() {
        const editor = document.getElementById('text');
        if (!editor) return;
        editor.classList.add('ai-writer-highlight');
        setTimeout(() => editor.classList.remove('ai-writer-highlight'), 2000);
    }
    
    function fillTitle(title) {
        if (!title) return false;
        const selectors = ['#title', 'input[name="title"]'];
        for (const selector of selectors) {
            const el = document.querySelector(selector);
            if (el) {
                el.value = title;
                triggerEvent(el, 'input');
                triggerEvent(el, 'change');
                return true;
            }
        }
        return false;
    }
    
    function fillTags(tagsString) {
        if (!tagsString) return false;
        const tagsArray = tagsString.replace(/[，]/g, ',').split(',').map(t => t.trim()).filter(t => t);
        if (!tagsArray.length) return false;
        const selectors = ['#tags', 'textarea[name="tags"]', 'input[name="tags"]'];
        for (const selector of selectors) {
            const el = document.querySelector(selector);
            if (el) {
                el.value = tagsArray.join(',');
                triggerEvent(el, 'input');
                triggerEvent(el, 'change');
                return true;
            }
        }
        return false;
    }
    
    function fillSlug(title) {
        if (CONFIG.enableSlug !== '1') return false;
        const slug = generateSlug(title || 'post');
        const selectors = ['#slug', 'input[name="slug"]'];
        for (const selector of selectors) {
            const el = document.querySelector(selector);
            if (el) {
                el.value = slug;
                triggerEvent(el, 'input');
                triggerEvent(el, 'change');
                return true;
            }
        }
        return false;
    }
    
    // ==================== AI处理 ====================
    async function processAction(action) {
        // 统一强制使用 format
        const effectiveAction = 'format';
        
        if (isProcessing) {
            showMessage('请等待当前操作完成', 'warning');
            return;
        }
        const editor = document.getElementById('text');
        if (!editor) {
            showMessage('找不到编辑器', 'error');
            return;
        }
        const content = editor.value.trim();
        if (!content) {
            showMessage('请先输入内容', 'warning');
            return;
        }
        
        // 获取作家风格下拉框的值
        let styleSelect = document.getElementById('ai-literary-style-active');
        if (!styleSelect) styleSelect = document.getElementById('ai-literary-style');
        const literaryStyle = styleSelect ? styleSelect.value : 'murakami';
        
        // 获取服务（虽然固定，但保留）
        let serviceSelect = document.getElementById('ai-service-select-active');
        if (!serviceSelect) serviceSelect = document.getElementById('ai-service-select');
        const service = serviceSelect ? serviceSelect.value : CONFIG.defaultService;
        
        let statusEl = document.getElementById('ai-processing-status-active');
        if (!statusEl) statusEl = document.getElementById('ai-processing-status');
        const statusText = statusEl ? statusEl.querySelector('#ai-status-text') : null;
        if (statusEl && statusText) {
            statusEl.style.display = 'flex';
            statusText.textContent = 'AI正在分析文章类型并处理，请稍候...';
        }
        
        isProcessing = true;
        try {
            const formData = new FormData();
            formData.append('content', content);
            formData.append('action', effectiveAction);
            formData.append('service', service);
            formData.append('literary_style', literaryStyle);  // 传递作家风格
            
            const response = await fetch(CONFIG.ajaxUrl, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const result = await response.json();
            if (!result.success) throw new Error(result.error || '未知错误');
            handleAIResponse(result.data, effectiveAction, content, editor);
            showMessage('智能排版完成', 'success');
        } catch (error) {
            console.error('AI处理失败:', error);
            showMessage(`处理失败: ${error.message}`, 'error');
        } finally {
            isProcessing = false;
            if (statusEl) statusEl.style.display = 'none';
        }
    }
    
    function handleAIResponse(aiResponse, action, originalContent, editor) {
        let content = aiResponse;
        try {
            const data = JSON.parse(aiResponse);
            if (data.type === 'structured') {
                if (data.title) fillTitle(data.title);
                if (data.tags) fillTags(data.tags);
                if (data.title) fillSlug(data.title);
                content = data.content || originalContent;
                if (CONFIG.debugMode === '1' && data.category) {
                    console.log('[AI Writer] 文章分类:', data.category);
                }
            }
        } catch (e) {
            // 非结构化，直接使用内容
        }
        editor.value = content;
        triggerEvent(editor, 'input');
        triggerEvent(editor, 'change');
        if (window.CodeMirror) {
            document.querySelectorAll('.CodeMirror').forEach(cm => {
                if (cm.CodeMirror) cm.CodeMirror.setValue(content);
            });
        }
        highlightEditor();
    }
    
    // ==================== 面板集成逻辑 ====================
    function getAttachPanelViaTabs() {
        return new Promise((resolve) => {
            if (typeof jQuery !== 'undefined' && jQuery('.typecho-option-tabs').length) {
                const tabs = jQuery('.typecho-option-tabs');
                let attachTab = null;
                tabs.find('li a').each(function() {
                    if ($(this).text().trim() === '附件') {
                        attachTab = $(this);
                        return false;
                    }
                });
                if (attachTab && attachTab.length) {
                    const panelId = attachTab.attr('href');
                    if (panelId) {
                        const panel = document.querySelector(panelId);
                        if (panel) {
                            resolve(panel);
                            return;
                        }
                    }
                }
                const secondTab = tabs.find('li:eq(1) a');
                if (secondTab.length) {
                    const panelId = secondTab.attr('href');
                    if (panelId) {
                        const panel = document.querySelector(panelId);
                        if (panel) {
                            resolve(panel);
                            return;
                        }
                    }
                }
            }
            resolve(null);
        });
    }
    
    function integrateIntoPanel(attachPanel) {
        if (!attachPanel) return false;
        if (document.getElementById('ai-writer-ai-panel')) return true;
        
        console.log('[AI Writer] 在面板中集成（智能排版模式）:', attachPanel);
        
        let originalWrapper = attachPanel.querySelector('.ai-original-attach-wrapper');
        if (!originalWrapper) {
            originalWrapper = document.createElement('div');
            originalWrapper.className = 'ai-original-attach-wrapper';
            const children = Array.from(attachPanel.children);
            children.forEach(child => originalWrapper.appendChild(child));
            attachPanel.appendChild(originalWrapper);
        }
        
        const aiPanelSource = document.getElementById('ai-writer-attach-extension');
        if (!aiPanelSource) {
            console.error('[AI Writer] 找不到 AI 面板模板');
            return false;
        }
        
        const aiPanel = document.createElement('div');
        aiPanel.id = 'ai-writer-ai-panel';
        aiPanel.className = 'ai-writer-ai-panel';
        aiPanel.innerHTML = aiPanelSource.innerHTML;
        // 修改ID避免冲突
        const styleSelect = aiPanel.querySelector('#ai-literary-style');
        if (styleSelect) styleSelect.id = 'ai-literary-style-active';
        const statusEl = aiPanel.querySelector('#ai-processing-status');
        if (statusEl) statusEl.id = 'ai-processing-status-active';
        
        aiPanel.style.display = 'block';
        aiPanel.style.marginTop = '20px';
        attachPanel.appendChild(aiPanel);
        originalWrapper.style.display = '';
        
        console.log('[AI Writer] 集成成功');
        return true;
    }
    
    function listenToTabs() {
        if (typeof jQuery !== 'undefined' && jQuery('.typecho-option-tabs').length) {
            jQuery('.typecho-option-tabs').on('tabsactivate', async (event, ui) => {
                const newPanel = ui.newPanel[0];
                const attachTabText = ui.newTab.text().trim();
                if (attachTabText === '附件' || (newPanel && newPanel.id && newPanel.id.includes('file'))) {
                    setTimeout(() => {
                        if (newPanel && !document.getElementById('ai-writer-ai-panel')) {
                            integrateIntoPanel(newPanel);
                        }
                    }, 200);
                }
            });
        } else {
            const tabs = document.querySelectorAll('.typecho-option-tabs li a');
            tabs.forEach(tab => {
                tab.addEventListener('click', async (e) => {
                    const text = tab.textContent.trim();
                    if (text === '附件') {
                        e.preventDefault();
                        const href = tab.getAttribute('href');
                        if (href) {
                            const panel = document.querySelector(href);
                            if (panel) {
                                document.querySelectorAll('.typecho-option-tab-panel').forEach(p => p.style.display = 'none');
                                panel.style.display = 'block';
                                setTimeout(() => {
                                    if (!document.getElementById('ai-writer-ai-panel')) {
                                        integrateIntoPanel(panel);
                                    }
                                }, 200);
                            }
                        }
                    }
                });
            });
        }
    }
    
    function initServiceSelect() {
        // 无需要初始化服务，但保留以防万一
    }
    
    function bindGlobalEvents() {
        document.body.addEventListener('click', (e) => {
            const btn = e.target.closest('.ai-feature-btn');
            if (btn && btn.dataset.action) {
                e.preventDefault();
                processAction('format');
            }
        });
    }
    
    async function init() {
        console.log('[AI Writer] 插件初始化 v' + CONFIG.version + '（智能排版合并版，支持作家风格选择）');
        bindGlobalEvents();
        listenToTabs();
        
        const attachPanel = await getAttachPanelViaTabs();
        if (attachPanel && !document.getElementById('ai-writer-ai-panel')) {
            integrateIntoPanel(attachPanel);
        }
        
        const observer = new MutationObserver(async () => {
            if (!document.getElementById('ai-writer-ai-panel')) {
                const panel = await getAttachPanelViaTabs();
                if (panel) integrateIntoPanel(panel);
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    if (CONFIG.debugMode === '1') {
        window.AiWriter = { config: CONFIG, processAction, showMessage, getAttachPanelViaTabs, integrateIntoPanel };
    }
})();
<?php
/**
 * AI写作助手处理类 - 智能排版合并版（支持自定义作家）
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class AiWriter_Action extends Typecho_Widget
{
    public function process()
    {
        $this->response->setContentType('application/json');
        
        try {
            $user = Typecho_Widget::widget('Widget_User');
            if (!$user->hasLogin()) {
                $this->response->throwJson([
                    'success' => false,
                    'error' => '未登录或会话已过期，请重新登录'
                ]);
                return;
            }
            
            $request = $this->request;
            $content = $request->get('content');
            $action = $request->get('action');
            // 强制使用 deepseek
            $service = 'deepseek';
            
            if (empty($content)) {
                $this->response->throwJson([
                    'success' => false,
                    'error' => '内容不能为空'
                ]);
                return;
            }
            
            $options = Helper::options()->plugin('AiWriter');
            
            if (empty($options->deepseekKey)) {
                $this->response->throwJson([
                    'success' => false,
                    'error' => '请先在插件设置中配置 DeepSeek API密钥'
                ]);
                return;
            }
            
            // 获取前端传递的作家风格，若无则使用设置中的
            $literaryStyle = $request->get('literary_style');
            if (empty($literaryStyle)) {
                $literaryStyle = $options->literaryStyle ?? 'murakami';
            }
            
            $result = $this->processWithAI($content, $action, $service, $options, $literaryStyle);
            
            $this->response->throwJson([
                'success' => true,
                'data' => $result
            ]);
            
        } catch (Exception $e) {
            $this->response->throwJson([
                'success' => false,
                'error' => '处理过程中出现异常：' . $e->getMessage()
            ]);
        }
    }
    
    private function processWithAI($content, $action, $service, $options, $literaryStyle = null)
    {
        // 统一使用 format 逻辑
        $prompt = $this->buildPrompt($content, 'format', $options, $literaryStyle);
        $response = $this->callAIService($prompt, $options);
        return $this->formatStructuredResponse($response);
    }
    
    private function buildPrompt($content, $action, $options, $literaryStyle = null)
    {
        // 预设风格映射
        $presetStyleMap = [
            'murakami' => '村上春树的写作风格：细腻的日常描写、隐喻丰富、语言平实而富有诗意',
            'yu hua' => '余华的写作风格：简洁有力、冷峻克制、叙事直接',
            'mo yan' => '莫言的写作风格：魔幻现实主义、乡土气息浓厚、语言富有想象力',
            'none' => '类似村上春树的写作风格：细腻的日常描写、隐喻丰富、但语言诙谐幽默、粗俗'
        ];
        
        // 解析自定义作家列表
        $customWriters = $options->customWriters ?: '';
        $customMap = [];
        if ($customWriters) {
            $lines = explode("\n", $customWriters);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $parts = explode('|', $line, 2);
                if (count($parts) == 2) {
                    $name = trim($parts[0]);
                    $desc = trim($parts[1]);
                    if ($name && $desc) {
                        $customMap[$name] = $desc;
                    }
                }
            }
        }
        
        // 确定最终使用的风格描述
        $selectedStyle = $literaryStyle ?: ($options->literaryStyle ?? 'murakami');
        $styleDesc = '';
        
        if (isset($presetStyleMap[$selectedStyle])) {
            $styleDesc = $presetStyleMap[$selectedStyle];
        } elseif (isset($customMap[$selectedStyle])) {
            $styleDesc = $customMap[$selectedStyle] . '的写作风格';
        } else {
            // 若未匹配，回退到默认
            $styleDesc = $presetStyleMap['murakami'];
        }

        $prompt = <<<PROMPT
你是一个专业的文字编辑和写作助手。请对以下文章内容进行智能处理，按照以下步骤：

1. **判断文章类型**：
   - 如果文章内容属于“生活类”（包括个人随笔、游记、摄影心得、情感叙事、生活感悟等），则标记为 `生活类`。
   - 如果文章内容属于“技术类”（包括技术教程、软件配置、编程指南、网络工程、产品说明书等），则标记为 `技术类`。
   - 如果难以区分，请优先根据内容主体判断，可参考文章中的专业术语、代码、步骤说明等特征。

2. **根据类型进行差异化润色和排版**：
   - **如果是生活类**：
     - 请以 **{$styleDesc}** 对文章进行润色，使文字更具文学性和感染力，但不要改变原意。
     - 适当调整段落，使文章节奏更佳，但不要过度增加Markdown标题（保持自然流畅）。
     - 保留原文中的图片占位符（如 [jpg]...[/jpg] 或类似标记），并可在[]中添加简洁的图片描述。
   - **如果是技术类**：
     - 对技术表述进行优化，确保术语准确、逻辑清晰、语言简洁。
     - 增加合适的Markdown标题层级（如 ## 一级标题，### 二级标题等），使文章结构分明，易于阅读。
     - 对于步骤、配置等，使用列表或编号，保持条理。
     - 保留原文中的代码块、图片占位符等格式。

3. **提取元数据**（无论哪种类型）：
   - 提取一个简洁明了、不超过20字的文章标题。
   - 提取1~3个最相关的中文关键词作为标签，用逗号分隔。
   - 提取优化后的完整正文内容（包含所有润色和排版改动）。

4. **输出格式**：
   请以严格的JSON格式返回结果，不要包含任何额外解释或标记：
   {
       "category": "生活类" 或 "技术类",
       "title": "提取的标题",
       "tags": "标签1,标签2,标签3",
       "content": "完整的润色后内容"
   }

文章内容如下：
{$content}
PROMPT;

        return $prompt;
    }
    
    private function formatStructuredResponse($aiResponse)
    {
        // 尝试提取JSON
        $jsonMatch = [];
        if (preg_match('/\{[^{}]*\}/s', $aiResponse, $jsonMatch)) {
            try {
                $data = json_decode($jsonMatch[0], true);
                if (json_last_error() === JSON_ERROR_NONE && isset($data['title'])) {
                    return json_encode([
                        'type' => 'structured',
                        'category' => $data['category'] ?? '未分类',
                        'title' => $data['title'] ?? '',
                        'tags' => $data['tags'] ?? '',
                        'content' => $data['content'] ?? $aiResponse
                    ], JSON_UNESCAPED_UNICODE);
                }
            } catch (Exception $e) {
                // 解析失败继续
            }
        }
        
        // 降级解析（按行提取标题、标签，剩余为内容）
        $lines = explode("\n", $aiResponse);
        $title = '';
        $tags = '';
        $content = '';
        
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                $content .= "\n";
                continue;
            }
            if (empty($title) && (strpos($trimmed, '标题') !== false || strpos($trimmed, 'Title') !== false)) {
                $title = str_replace(['标题：', '标题:', 'Title:', 'title:'], '', $trimmed);
                continue;
            }
            if (empty($tags) && (strpos($trimmed, '标签') !== false || strpos($trimmed, '关键词') !== false || strpos($trimmed, 'Tags') !== false)) {
                $clean = preg_replace('/^(标签|关键词|Tags|tags)[：:]*\s*/u', '', $trimmed);
                $clean = preg_replace('/[^,，\p{Han}\w\s]+/u', '', $clean);
                $tags = $clean;
                continue;
            }
            $content .= $line . "\n";
        }
        
        if (empty($title)) {
            $title = '未命名文章';
        }
        if (empty($tags)) {
            $tags = '未分类';
        }
        
        return json_encode([
            'type' => 'structured',
            'category' => '未分类',
            'title' => $title,
            'tags' => $tags,
            'content' => trim($content)
        ], JSON_UNESCAPED_UNICODE);
    }
    
    private function callAIService($prompt, $options)
    {
        $apiKey = $options->deepseekKey;
        $model = $options->deepseekModel ?: 'deepseek-flash';
        $timeout = intval($options->timeout) ?: 60;
        
        $url = 'https://api.deepseek.com/v1/chat/completions';
        
        $data = [
            "model" => $model,
            "messages" => [
                ["role" => "user", "content" => $prompt]
            ],
            "temperature" => 0.7,
            "max_tokens" => 6000
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('DeepSeek网络请求失败：' . $error);
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['choices'][0]['message']['content'])) {
            return trim($result['choices'][0]['message']['content']);
        } else {
            $errorMsg = isset($result['error']['message']) ? $result['error']['message'] : '未知错误';
            throw new Exception('DeepSeek调用失败（HTTP ' . $httpCode . '）：' . $errorMsg);
        }
    }
}
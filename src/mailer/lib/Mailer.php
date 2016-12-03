<?php
/**
 * tp-mailer [A powerful and beautiful php mailer for All of ThinkPHP and Other PHP Framework based SwiftMailer]
 *
 * @author    yuan1994 <tianpian0805@gmail.com>
 * @link      https://github.com/yuan1994/tp-mailer
 * @copyright 2016 yuan1994 all rights reserved.
 * @license   http://www.apache.org/licenses/LICENSE-2.0
 */

namespace mailer\lib;

use Swift_Mailer;
use Swift_Message;

abstract class Mailer
{
    // 单例
    protected static $instance;
    /**
     * @var \Swift_Message
     */
    protected $message;
    /**
     * @var \Swift_SmtpTransport|\Swift_SendmailTransport|\Swift_MailTransport
     */
    protected $transport;
    // 以行设置文本的内容
    protected $line = [];
    // 注册组件列表
    protected $plugin = [];
    // 错误信息
    protected $errMsg;
    // 发送失败的帐号
    protected $fails;
    // 左定界符
    protected $LDelimiter = '{';
    // 右定界符
    protected $RDelimiter = '}';

    public static function instance($transport = null)
    {
        if (null === self::$instance) {
            self::$instance = new static($transport);
        }

        return self::$instance;
    }

    public function __construct($transport = null)
    {
        $this->transport = $transport;
        $this->init();
    }

    /**
     * 重置实例
     *
     * @return $this
     */
    public function init()
    {
        $this->message = Swift_Message::newInstance(
            null,
            null,
            Config::get('mail.content_type'),
            Config::get('mail.charset')
        )
            ->setFrom(Config::get('mail.addr'), Config::get('mail.name'));

        return $this;
    }

    /**
     * 载入一个模板作为邮件内容
     *
     * 实现$template指定模板路径, $param设置模板替换参数, 最后能正常渲染出HTML
     *
     * @param string $template
     * @param array $param
     * @param array $config
     * @return Mailer
     */
    abstract public function view($template, $param = [], $config = []);

    /**
     * 设置邮件主题
     *
     * @param $subject
     * @return $this
     */
    public function subject($subject)
    {
        $this->message->setSubject($subject);

        return $this;
    }

    /**
     * 设置发件人
     *
     * @param $address
     * @param null $name
     * @return $this
     */
    public function from($address, $name = null)
    {
        $this->message->setFrom($address, $name);

        return $this;
    }

    /**
     * 设置收件人
     *
     * @param $address
     * @param null $name
     * @return $this
     */
    public function to($address, $name = null)
    {
        $this->message->setTo($address, $name);

        return $this;
    }

    /**
     * 设置邮件内容为HTML内容
     *
     * @param $content
     * @param array $param
     * @param array $config
     * @return $this
     */
    public function html($content, $param = [], $config = [])
    {
        if ($param) {
            $content = strtr($content, $this->parseParam($param, $config));
        }
        $this->message->setBody($content, MailerConfig::CONTENT_HTML);

        return $this;
    }

    /**
     * 设置邮件内容为纯文本内容
     *
     * @param $content
     * @param array $param
     * @param array $config
     * @return $this
     */
    public function text($content, $param = [], $config = [])
    {
        if ($param) {
            $content = strtr($content, $this->parseParam($param, $config));
        }
        $this->message->setBody($content, MailerConfig::CONTENT_PLAIN);

        return $this;
    }

    /**
     * 设置邮件内容为纯文本内容
     *
     * @param $content
     * @param array $param
     * @param array $config
     * @return Mailer
     */
    public function raw($content, $param = [], $config = [])
    {
        return $this->text($content, $param, $config);
    }

    /**
     * 添加一行数据
     *
     * @param $content
     * @param array $param
     * @param array $config
     * @return $this
     */
    public function line($content = '', $param = [], $config = [])
    {
        $this->line[] = strtr($content, $this->parseParam($param, $config));

        return $this;
    }

    /**
     * 添加附件
     *
     * @param string $filePath
     * @param string|\Swift_Attachment|null $attr
     * @return $this
     */
    public function attach($filePath, $attr = null)
    {
        $attachment = \Swift_Attachment::fromPath($filePath);
        if ($attr instanceof \Closure) {
            call_user_func_array($attr, [& $attachment, $this]);
        } elseif ($attr) {
            $attachment->setFilename($this->cnEncode($attr));
        } else {
            // 修复中文文件名乱码bug
            $tmp = str_replace("\\", '/', $filePath);
            $tmp = explode('/', $tmp);
            $filename = end($tmp);
            $attachment->setFilename($this->cnEncode($filename));
        }
        $this->message->attach($attachment);

        return $this;
    }

    /**
     * Signed/Encrypted Message
     *
     * @param \Swift_Signers_SMimeSigner $smimeSigner
     * @return $this
     */
    public function signCertificate($smimeSigner)
    {
        if ($smimeSigner instanceof \Closure) {
            $signer = \Swift_Signers_SMimeSigner::newInstance();
            call_user_func_array($smimeSigner, [& $signer]);
            $this->message->attachSigner($signer);
        }

        return $this;
    }

    /**
     * 设置字符编码
     *
     * @param string $charset
     * @return $this
     */
    public function charset($charset)
    {
        $this->message->setCharset($charset);

        return $this;
    }

    /**
     * 设置邮件最大长度
     *
     * @param int $length
     * @return $this
     */
    public function lineLength($length)
    {
        $this->message->setMaxLineLength($length);

        return $this;
    }

    /**
     * 设置优先级
     *
     * @param int $priority
     * @return $this
     */
    public function priority($priority = MailerConfig::PRIORITY_HIGHEST)
    {
        $this->message->setPriority($priority);

        return $this;
    }

    /**
     * Requesting a Read Receipt
     *
     * @param string $address
     * @return $this
     */
    public function readReceiptTo($address)
    {
        $this->message->setReadReceiptTo($address);

        return $this;
    }

    /**
     * 注册SwiftMailer插件, 详情请见 http://swiftmailer.org/docs/plugins.html
     *
     * @param object $plugin
     */
    public function registerPlugin($plugin)
    {
        $this->plugin[] = $plugin;
    }

    /**
     * 获取头信息
     *
     * @return \Swift_Mime_HeaderSet
     */
    public function getHeaders()
    {
        return $this->message->getHeaders();
    }

    /**
     * 获取头信息 (字符串)
     *
     * @return string
     */
    public function getHeadersString()
    {
        return $this->getHeaders()->toString();
    }

    /**
     * 发送邮件
     *
     * @param \Closure|null $message
     * @param \Closure|string|null $transport
     * @param \Closure|null $send
     * @return bool|int
     * @throws MailerException
     */
    public function send($message = null, $transport = null, $send = null)
    {
        try {
            // 获取将行数据设置到message里
            if ($this->line) {
                $this->message->setBody(implode("\r\n", $this->line), MailerConfig::CONTENT_PLAIN);
                $this->line = [];
            }
            // 匿名函数
            if ($message instanceof \Closure) {
                call_user_func_array($message, [& $this, & $this->message]);
            }
            // 邮件驱动
            if (null === $transport && !$this->transport) {
                $transport = $this->transport;
            }
            // 直接传递的是Swift_Transport对象
            if (
                $transport instanceof \Swift_SmtpTransport
                || $transport instanceof \Swift_SendmailTransport
                || $transport instanceof \Swift_MailTransport
            ) {
                $transportDriver = $transport;
            } else {
                // 其他匿名函数和驱动名称
                $transportInstance = Transport::instance();
                if ($transport instanceof \Closure) {
                    $transportDriver = call_user_func_array($transport, [$transportInstance]);
                } else {
                    $transportDriver = $transportInstance->getDriver($transport);
                }
            }

            $swiftMailer = Swift_Mailer::newInstance($transportDriver);
            // debug模式记录日志
            if (Config::get('mail.debug')) {
                Log::write(var_export($this->getHeadersString(), true), 'MAILER');
            }

            // 注册插件
            if ($this->plugin) {
                foreach ($this->plugin as $plugin) {
                    $swiftMailer->registerPlugin($plugin);
                }
                $this->plugin = [];
            }

            // 发送邮件
            if ($send instanceof \Closure) {
                call_user_func_array($send, [$swiftMailer, $this]);
            } else {
                return $swiftMailer->send($this->message, $this->fails);
            }
        } catch (\Exception $e) {
            $this->errMsg = $e->getMessage();
            if (Config::get('mail.debug')) {
                // 调试模式直接抛出异常
                throw new MailerException($e->getMessage());
            } else {
                return false;
            }
        }
    }

    /**
     * 获取错误信息
     *
     * @return mixed
     */
    public function getError()
    {
        return $this->errMsg;
    }

    /**
     * 获取发送错误的邮箱帐号列表
     *
     * @return mixed
     */
    public function getFails()
    {
        return $this->fails;
    }

    /**
     * 中文文件名编码, 防止乱码
     *
     * @param $string
     * @return string
     */
    public function cnEncode($string)
    {
        return "=?UTF-8?B?" . base64_encode($string) . "?=";
    }

    /**
     * 将参数中的key值替换为可替换符号
     *
     * @param array $param
     * @param array $config
     * @return mixed
     */
    protected function parseParam(array $param, array $config = [])
    {
        $ret = [];
        $leftDelimiter = isset($config['left_delimiter'])
            ? $config['left_delimiter'] : (
            Config::has('mail.left_delimiter')
                ? Config::get('mail.left_delimiter')
                : $this->LDelimiter
            );
        $rightDelimiter = isset($config['right_delimiter'])
            ? $config['right_delimiter'] : (
            Config::has('mail.right_delimiter')
                ? Config::get('mail.right_delimiter')
                : $this->RDelimiter
            );
        foreach ($param as $k => $v) {
            $ret[$leftDelimiter . $k . $rightDelimiter] = $v;
        }

        return $ret;
    }
}

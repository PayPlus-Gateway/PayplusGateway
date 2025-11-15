<?php

namespace Payplus\PayplusGateway\Block\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\UrlInterface;

class SyncButton extends Field
{
    protected $urlBuilder;

    public function __construct(
        Context $context,
        UrlInterface $urlBuilder,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Render button
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $buttonId = 'payplus_sync_orders_button';
        $resultContainerId = 'payplus_sync_result';
        
        $syncUrl = $this->urlBuilder->getUrl('payplus/syncorders/index', ['_secure' => true]);
        
        $html = '<div id="' . $resultContainerId . '" style="margin-top: 10px;"></div>';
        $html .= '<button type="button" id="' . $buttonId . '" class="action-default scalable" style="margin-top: 10px;">';
        $html .= '<span>' . __('Sync Orders Now') . '</span>';
        $html .= '</button>';
        
        $html .= '<script>
            require(["jquery", "mage/url", "mage/translate"], function($, urlBuilder, $t) {
                $("#' . $buttonId . '").on("click", function() {
                    var button = $(this);
                    var resultDiv = $("#' . $resultContainerId . '");
                    var buttonSpan = button.find("span");
                    
                    // Get form key from page - try multiple methods
                    var formKey = null;
                    if (typeof FORM_KEY !== "undefined") {
                        formKey = FORM_KEY;
                    } else if ($("input[name=\'form_key\']").length > 0) {
                        formKey = $("input[name=\'form_key\']").val();
                    } else if ($("#edit_form input[name=\'form_key\']").length > 0) {
                        formKey = $("#edit_form input[name=\'form_key\']").val();
                    } else if (window.adminFormKey) {
                        formKey = window.adminFormKey;
                    }
                    
                    if (!formKey) {
                        resultDiv.html("<div style=\'background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin-top: 10px; border-radius: 4px; color: #721c24;\'><strong>' . __('Error:') . '</strong> ' . __('Form key not found. Please refresh the page.') . '</div>");
                        return;
                    }
                    
                    button.prop("disabled", true);
                    buttonSpan.text("' . __('Syncing...') . '");
                    resultDiv.html("");
                    
                    // Debug: log URL and form key (remove in production)
                    console.log("Sync URL:", "' . $syncUrl . '");
                    console.log("Form Key:", formKey ? "Found" : "Missing");
                    
                    $.ajax({
                        url: "' . $syncUrl . '",
                        type: "POST",
                        dataType: "json",
                        data: {
                            form_key: formKey
                        },
                        traditional: true,
                        success: function(response) {
                            button.prop("disabled", false);
                            buttonSpan.text("' . __('Sync Orders Now') . '");
                            
                            if (response.success) {
                                var report = response.report;
                                var html = "<div style=\'background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; margin-top: 10px; border-radius: 4px;\'>";
                                html += "<strong>' . __('Sync Completed Successfully!') . '</strong><br/>";
                                html += "<strong>' . __('Total Checked:') . '</strong> " + report.total_checked + "<br/>";
                                html += "<strong style=\'color: green;\'>' . __('Successful:') . '</strong> " + report.successful + "<br/>";
                                html += "<strong style=\'color: red;\'>' . __('Failed:') . '</strong> " + report.failed + "<br/>";
                                html += "<strong>' . __('Skipped:') . '</strong> " + report.skipped + "<br/>";
                                
                                if (report.processed_orders && report.processed_orders.length > 0) {
                                    html += "<br/><strong>' . __('Processed Orders:') . '</strong><ul>";
                                    report.processed_orders.forEach(function(order) {
                                        html += "<li>Order #" + order.order_id + " - Transaction: " + (order.transaction_uid || "N/A") + " - Amount: " + (order.amount || "N/A") + "</li>";
                                    });
                                    html += "</ul>";
                                }
                                
                                if (report.errors && report.errors.length > 0) {
                                    html += "<br/><strong style=\'color: red;\'>' . __('Errors:') . '</strong><ul>";
                                    report.errors.forEach(function(error) {
                                        html += "<li>Order #" + (error.order_id || "N/A") + ": " + error.error + "</li>";
                                    });
                                    html += "</ul>";
                                }
                                
                                html += "</div>";
                                resultDiv.html(html);
                            } else {
                                resultDiv.html("<div style=\'background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin-top: 10px; border-radius: 4px; color: #721c24;\'><strong>' . __('Error:') . '</strong> " + (response.message || "' . __('Unknown error') . '") + "</div>");
                            }
                        },
                        error: function(xhr, status, error) {
                            button.prop("disabled", false);
                            buttonSpan.text("' . __('Sync Orders Now') . '");
                            var errorMsg = "' . __('Request failed') . '";
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                errorMsg = xhr.responseJSON.message;
                            } else if (xhr.responseText) {
                                try {
                                    var errorResponse = JSON.parse(xhr.responseText);
                                    if (errorResponse.message) {
                                        errorMsg = errorResponse.message;
                                    }
                                } catch(e) {
                                    errorMsg = xhr.status + ": " + error;
                                }
                            }
                            resultDiv.html("<div style=\'background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin-top: 10px; border-radius: 4px; color: #721c24;\'><strong>' . __('Error:') . '</strong> " + errorMsg + "</div>");
                        }
                    });
                });
            });
        </script>';
        
        return $html;
    }
}


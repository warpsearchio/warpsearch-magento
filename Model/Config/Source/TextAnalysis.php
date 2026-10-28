<?php
declare(strict_types=1);

namespace WarpSearch\Search\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class TextAnalysis implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'standard', 'label' => __('Standard (case folding, any language)')],
            ['value' => 'english', 'label' => __('English (accent folding and stemming)')],
        ];
    }
}

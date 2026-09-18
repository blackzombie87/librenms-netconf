<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

class DefinitionMatcher
{
    /**
     * @param  iterable<Definition>  $definitions
     * @return list<Definition>
     */
    public function matching(DeviceFacts $facts, iterable $definitions): array
    {
        $result = [];
        foreach ($definitions as $definition) {
            if ($definition->matches($facts)) {
                $result[] = $definition;
            }
        }

        return $result;
    }
}

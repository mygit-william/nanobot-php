<?php

namespace App\Skills;

use Symfony\Component\Yaml\Yaml;

class SkillManager
{
    private array $skills = [];

    public function __construct()
    {
    }

    private function loadSkills(): void
    {
        $skillDir = __DIR__ . '/../../skills';
        // echo $skillDir.PHP_EOL;
        if (!is_dir($skillDir)) {
            mkdir($skillDir, 0777, true);
            return;
        }

        foreach (scandir($skillDir) as $folder) {
            if ($folder === '.' || $folder === '..') continue;

            $skillFile = $skillDir . "/{$folder}/SKILL.md";
            // echo $folder.PHP_EOL;ç
            if (file_exists($skillFile)) {
                $content = file_get_contents($skillFile);
                
                // 解析 YAML 头部
                if (preg_match('/^---\s*(.*?)\s*---/s', $content, $matches)) {
                    $yamlData = $matches[1];
                    $meta = Yaml::parse($yamlData);
                    
                    // 核心修改：不再寻找 Handler.php
                    // 直接实例化通用的 OpenClawSkill
                    $this->skills[$meta['name']] = new OpenClawSkill($meta);
                }
            }
        }
    }

    public function getToolsDefinition(): array
    {
        $tools = [];
        $str='-----available-skills------'.PHP_EOL;
        $this->loadSkills();
        foreach ($this->skills as $name => $skill) {
            $descption=$skill->getDescription();
            $str .= "name:$name\ndescription:$descption\n";
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $skill->getDescription(),
                    'parameters' => $skill->getParameters()
                ]
            ];
        }
        $str .='-----available-skills------'.PHP_EOL;
        // var_dump($str);die;
        return $tools;
    }

    public function execute(string $name, array $arguments): string
    {
        if (!isset($this->skills[$name])) {
            throw new \Exception("技能不存在: $name");
        }
        return $this->skills[$name]->execute($arguments);
    }
}
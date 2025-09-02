#!/bin/bash

# Output file
output="combined_classes.php"

# Clear previous output if exists
> "$output"

# Recursively find all PHP files in classes folder and append their content
find classes -type f -name "*.php" | while read file; do
    echo "/* File: $file */" >> "$output"
    cat "$file" >> "$output"
    echo -e "\n\n" >> "$output"
done

echo "All PHP files combined into $output"

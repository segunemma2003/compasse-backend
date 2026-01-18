#!/bin/bash

# Fix all controllers that use School::where('subdomain'...)
# In tenant context, we should use School::first() since there's only one school per tenant DB

for file in app/Http/Controllers/AnalyticsController.php \
           app/Http/Controllers/ArmController.php \
           app/Http/Controllers/ContinuousAssessmentController.php \
           app/Http/Controllers/GradingSystemController.php \
           app/Http/Controllers/ResultController.php \
           app/Http/Controllers/ScoreboardController.php; do
  
  echo "Fixing $file..."
  
  # Replace the pattern
  sed -i.bak 's/\$subdomain = \$request->header('"'"'X-Subdomain'"'"');/\/\/ In tenant context, get the first (and only) school/g' "$file"
  sed -i.bak 's/\$school = School::where('"'"'subdomain'"'"', \$subdomain)->first();/\$school = School::first();/g' "$file"
  
  # Remove backup file
  rm -f "${file}.bak"
done

echo "Done!"

#!/bin/bash

# Wait and Test Script
# Waits 7 minutes after deployment, then runs post-deployment tests

echo "⏳ Waiting 7 minutes for deployment to complete..."
echo "   Started at: $(date)"
echo ""

# Countdown timer
for i in {420..1}; do
    minutes=$((i / 60))
    seconds=$((i % 60))
    printf "\r   Time remaining: %02d:%02d" $minutes $seconds
    sleep 1
done

echo ""
echo ""
echo "✅ Wait complete! Starting tests..."
echo "   Testing at: $(date)"
echo ""

# Run the test script
php test-post-deployment.php

exit_code=$?

echo ""
echo "Test completed at: $(date)"

if [ $exit_code -eq 0 ]; then
    echo "🎉 All tests passed!"
else
    echo "❌ Some tests failed. Check the output above."
fi

exit $exit_code

